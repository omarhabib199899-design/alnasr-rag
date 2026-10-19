<?php

namespace App\Services\AI;

use App\Services\Stats\CurrentInventory;
use App\Services\Stats\MetricCatalog;
use App\Services\Stats\StatsQueryBuilder;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * Orchestrates a chat answer in two tiers:
 *   1. Diagnostic — the question maps to a catalog metric, so the answer
 *      is a real computed number/rows (via NlToMetricSpecService + the
 *      same StatsQueryBuilder the dashboard uses).
 *   2. Advisory — it doesn't map to a metric, so the LLM reasons over a
 *      snapshot of the real computed numbers we hand it, and the answer
 *      is clearly labeled as a suggestion, not a fact.
 *
 * Whichever of inventory/sales/purchase_orders/returns have been uploaded
 * are all in play — the resolved metric's subject decides which import the
 * query runs against. customer_ledger/customer_credit (added 2026-10-04,
 * covering ar_ledger_entries/customer_credit_usage — the same Customer 360
 * data CustomerController shows) are different: company-wide live tables,
 * not a per-user import, so "do we have data" just checks the table isn't
 * empty rather than resolving import ids — see COMPANY_WIDE_SUBJECTS.
 */
class ChatAnswerService
{
    use RetriesTransientOpenAiErrors;

    private const SUBJECTS = ['inventory', 'sales', 'purchase_orders', 'returns', 'customer_ledger', 'customer_credit'];

    private const COMPANY_WIDE_SUBJECTS = ['customer_ledger', 'customer_credit'];

    public function __construct(
        private readonly NlToMetricSpecService $nlToSpec,
        private readonly MetricCatalog $catalog,
        private readonly StatsQueryBuilder $stats,
        private readonly CurrentInventory $currentInventory,
    ) {}

    /**
     * @return array{type: string, content: string, computed_spec: ?array, citation_data: ?array}
     */
    public function answer(int $userId, string $question): array
    {
        $importIds = [];
        foreach (self::SUBJECTS as $subject) {
            // StatsQueryBuilder ignores this value entirely for
            // COMPANY_WIDE_SUBJECTS (see its IMPORT_SCOPED_SUBJECTS) — [1]
            // here is just a non-empty sentinel meaning "this subject has
            // data available", matching the empty()-check shape every other
            // subject already uses below.
            $importIds[$subject] = in_array($subject, self::COMPANY_WIDE_SUBJECTS, true)
                ? ($this->companyWideSubjectHasData($subject) ? [1] : [])
                : $this->currentInventory->relevantImportIds($userId, $subject);
        }

        if (collect($importIds)->every(fn ($ids) => empty($ids))) {
            return [
                'type' => 'unanswerable',
                'content' => "I don't have any data yet — upload a file first.",
                'computed_spec' => null,
                'citation_data' => null,
            ];
        }

        $spec = $this->nlToSpec->resolve($question);

        if ($spec !== null) {
            $definition = $this->catalog->metricDefinition($spec->metric);
            $subjectImportIds = $importIds[$definition['subject']] ?? [];

            if (empty($subjectImportIds)) {
                $needed = str_replace('_', ' ', $definition['subject']);

                return [
                    'type' => 'unanswerable',
                    'content' => "That needs {$needed} data, which hasn't been uploaded yet.",
                    'computed_spec' => null,
                    'citation_data' => null,
                ];
            }

            $result = $this->stats->run($spec, $subjectImportIds);

            return [
                'type' => 'computed',
                'content' => $this->formatComputedAnswer($result),
                'computed_spec' => [
                    'metric' => $spec->metric,
                    'group_by' => $spec->groupBy,
                    'filters' => $spec->filters,
                    'top_n' => $spec->topN,
                ],
                'citation_data' => $result,
            ];
        }

        return $this->answerAdvisory($question, $importIds);
    }

    private function formatComputedAnswer(array $result): string
    {
        return match ($result['type']) {
            'scalar' => $result['label'].': '.number_format($result['value'], 2),
            'breakdown' => $result['label'].' by '.$result['group_by'].":\n".collect($result['rows'])
                ->map(fn ($r) => "- {$r['label']}: ".number_format($r['value'], 2))
                ->implode("\n")
                .$this->highlightExtreme($result['rows']),
            'listing' => $result['label'].":\n".collect($result['rows'])
                ->map(fn ($r) => "- {$r['sku']} {$r['name']} (stock: {$r['stock']}, price: {$r['price']})")
                ->implode("\n"),
            default => 'No data.',
        };
    }

    /**
     * Breakdown rows are shown in whatever order the metric naturally sorts
     * in (chronological for a month trend, value-desc for most others) —
     * so a superlative question ("which month had the most returns") isn't
     * always answered by just reading the first row. This deterministically
     * calls out the max from the already-computed numbers, no guessing.
     */
    private function highlightExtreme(array $rows): string
    {
        if (count($rows) < 2) {
            return '';
        }

        $top = collect($rows)->sortByDesc('value')->first();

        return "\n\nHighest: {$top['label']} (".number_format($top['value'], 2).')';
    }

    /**
     * @param  array<string, array<int, int>>  $importIds  subject => relevant import ids
     */
    private function answerAdvisory(string $question, array $importIds): array
    {
        $snapshot = [];
        foreach ($importIds as $subject => $subjectImportIds) {
            if (! empty($subjectImportIds)) {
                $snapshot[$subject] = $this->gatherSnapshot($subject, $subjectImportIds);
            }
        }
        $context = json_encode($snapshot, JSON_PRETTY_PRINT);

        $systemPrompt = 'You are a business assistant covering inventory, sales, purchase orders, returns, and '.
            "customer accounts receivable/credit. Answer using ONLY the numbers in DATA below — never invent ".
            "numbers that aren't there. If the question needs information not present in DATA ".
            '(e.g. competitor prices, marketing performance, customer demographics), say so plainly instead of '.
            'guessing. Always frame your response as a suggestion to consider, not a guaranteed outcome — start '.
            "with a short disclaimer sentence.\n\nDATA:\n".$context;

        $response = $this->withOpenAiRetry(fn () => OpenAI::chat()->create([
            'model' => config('openai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $question],
            ],
        ]));

        $content = $response->choices[0]->message->content ?? "I couldn't generate a response.";

        return [
            'type' => 'advisory',
            'content' => $content,
            'computed_spec' => null,
            'citation_data' => ['context_used' => $snapshot],
        ];
    }

    /**
     * Every catalog metric for this subject, computed with its defaults —
     * the same numbers the dashboard shows, reused as grounding context so
     * advisory answers stay tied to real data. Automatically picks up new
     * metrics as the catalog grows, nothing to hand-maintain here.
     */
    private function gatherSnapshot(string $subject, array $importIds): array
    {
        $snapshot = [];
        foreach ($this->catalog->metrics() as $key => $definition) {
            if ($definition['subject'] !== $subject) {
                continue;
            }
            try {
                $snapshot[$key] = $this->stats->run($this->catalog->buildSpec(['metric' => $key]), $importIds);
            } catch (Throwable) {
                continue;
            }
        }

        return $snapshot;
    }

    /**
     * "Has data" for a company-wide subject (see COMPANY_WIDE_SUBJECTS) is
     * just a non-empty-table check — these aren't tied to any one user's
     * Import rows the way the other 4 subjects are.
     */
    private function companyWideSubjectHasData(string $subject): bool
    {
        return match ($subject) {
            'customer_ledger' => DB::table('ar_ledger_entries')->exists(),
            'customer_credit' => DB::table('customer_credit_usage')->exists(),
            default => false,
        };
    }
}
