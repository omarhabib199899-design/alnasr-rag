<?php

namespace App\Services\Customer360;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of the Lovable portal's Entity 360 functions (Sales Reps / Sales Owners / Data Entry) that have no
 * Customer 360 counterpart — the shared tab functions live in Customer360Data and take the same scope/value.
 * Export: scratchpad entity360_sales_reps_owners_data_entry_export.md + entity360_customer_actions_daily_snapshot.md.
 *
 * Portfolio = customers whose scope field equals the value exactly (checked live 2026-10-19: no case/space
 * variants of any entity name exist, so MySQL's case-insensitive collation matches the same rows as Postgres).
 */
class Entity360Data
{
    public const SCOPES = ['rep_name', 'management', 'sales_owner', 'data_entry'];

    public const CLASSIFICATION_GROUPS = ['sales', 'profitability', 'collection', 'payment_trend', 'ar_risk', 'documents'];

    private const CUSTOMERS = 'zoho_raw_customers';

    private const AR = 'zoho_ar_sales';

    private Carbon $today;

    public function __construct(private readonly string $scope, private readonly string $value)
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('invalid scope');
        }
        $this->today = Carbon::parse(Carbon::now('Asia/Riyadh')->toDateString());
    }

    /** customer_directory(_dimension) — one row per stored value; NULL/blank = "Unassigned". */
    public static function directory(string $dimension): array
    {
        if (! in_array($dimension, self::SCOPES, true)) {
            throw new \InvalidArgumentException('invalid dimension');
        }
        $ar = Schema::hasTable(self::AR)
            ? 'LEFT JOIN (SELECT customer_id, SUM(statement_amount) bal FROM '.self::AR.' GROUP BY customer_id) ar ON ar.customer_id = c.customer_id'
            : 'LEFT JOIN (SELECT NULL customer_id, 0 bal) ar ON 1 = 0';

        return array_map(fn ($r) => [
            'group_name' => (string) $r->g, 'is_unassigned' => (bool) $r->u,
            'customers_count' => (int) $r->n, 'outstanding_balance' => (float) $r->bal,
        ], DB::select("SELECT CASE WHEN NULLIF(TRIM(c.`{$dimension}`), '') IS NULL THEN 'Unassigned' ELSE c.`{$dimension}` END g,
                NULLIF(TRIM(c.`{$dimension}`), '') IS NULL u, COUNT(DISTINCT c.customer_id) n, COALESCE(SUM(ar.bal), 0) bal
            FROM ".self::CUSTOMERS." c {$ar} GROUP BY g, u ORDER BY u, g"));
    }

    /** portfolio_overview_distributions — status, account type, WH city, payment terms, management, sales owner, data entry. */
    public function distributions(): array
    {
        $pf = DB::table(self::CUSTOMERS)->where($this->scope, $this->value)
            ->get(['customer_id', 'status', 'account_type', 'wh_city', 'payments_due', 'management', 'sales_owner', 'data_entry']);
        $n = $pf->pluck('customer_id')->unique()->count();
        $sections = ['status' => 'status', 'account_type' => 'account_type', 'wh_city' => 'wh_city', 'payment_terms' => 'payments_due',
            'management' => 'management', 'sales_owner' => 'sales_owner', 'data_entry' => 'data_entry'];

        $out = [];
        ksort($sections); // ORDER BY section
        foreach ($sections as $section => $col) {
            $g = [];
            foreach ($pf as $c) {
                $v = $c->{$col};
                $v = ($v === null || trim((string) $v) === '') ? null : (string) $v;
                $g[$v ?? "\0"][(string) $c->customer_id] = true;
            }
            $rows = [];
            foreach ($g as $v => $ids) {
                $rows[] = ['section' => $section, 'value' => $v === "\0" ? null : (string) $v, 'is_missing' => $v === "\0", 'customers' => count($ids),
                    'pct' => $n ? round(count($ids) / $n * 100, 2) : null, 'portfolio_customers' => $n];
            }
            usort($rows, fn ($a, $b) => [$a['is_missing'], -$a['customers'], $a['value']] <=> [$b['is_missing'], -$b['customers'], $b['value']]);
            array_push($out, ...$rows);
        }

        return $out;
    }

    /** portfolio_active_customers — customers with positive YTD / MTD Net Sales. */
    public function activeCustomers(): ?array
    {
        $ids = DB::table(self::CUSTOMERS)->where($this->scope, $this->value)->pluck('customer_id')->map(fn ($v) => (string) $v)->unique()->values()->all();
        $ytd = $mtd = 0;
        if ($ids && Schema::hasTable(self::AR)) {
            $y0 = $this->today->copy()->startOfYear()->toDateString();
            $m0 = $this->today->copy()->startOfMonth()->toDateString();
            $r = DB::selectOne('SELECT SUM(ytd > 0) y, SUM(mtd > 0) m FROM (SELECT customer_id, SUM(kpi_amount) ytd, SUM(CASE WHEN transaction_date >= ? THEN kpi_amount END) mtd
                FROM '.self::AR.' WHERE customer_id IN ('.implode(',', array_fill(0, count($ids), '?')).")
                AND category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ? AND transaction_date >= ? AND transaction_date <= ?
                GROUP BY customer_id) x", array_merge([$m0], $ids, [Customer360Data::D0, $y0, $this->today->toDateString()]));
            $ytd = (int) $r->y;
            $mtd = (int) $r->m;
        }

        return ['active_year' => $ytd, 'active_month' => $mtd, 'portfolio_customers' => count($ids), 'as_of_date' => $this->today->toDateString()];
    }

    /** zoho.portfolio_classification_values — one row per (group, customer) with a non-blank classification. */
    private function classificationValues(): array
    {
        $pf = DB::table(self::CUSTOMERS)->whereNotNull('customer_number')->where($this->scope, $this->value)
            ->get(['customer_id', 'customer_number', 'customer_name'])->keyBy('customer_number');
        if ($pf->isEmpty()) {
            return [];
        }
        $sources = [
            ['sales', 1, 'zoho_sales_classification', 'sales_classification'],
            ['profitability', 2, 'zoho_profitability_classification', 'profitability_classification'],
            ['collection', 3, 'zoho_collection_classification', 'collection_classification'],
            ['payment_trend', 4, 'zoho_collection_classification', 'payment_timing_pattern'],
            ['ar_risk', 5, 'zoho_ar_risk_classification', 'final_risk'],
            ['documents', 6, 'zoho_documents_classification', 'documents_classification'],
        ];
        $out = [];
        foreach ($sources as [$g, $o, $table, $col]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->whereIn('customer_number', $pf->keys()->all())->get(['customer_number', $col]) as $r) {
                if ($r->{$col} === null || trim((string) $r->{$col}) === '') {
                    continue;
                }
                $c = $pf[$r->customer_number];
                $out[] = ['group_key' => $g, 'group_order' => $o, 'class_value' => (string) $r->{$col},
                    'customer_id' => (string) $c->customer_id, 'customer_number' => $c->customer_number, 'customer_name' => $c->customer_name];
            }
        }

        return $out;
    }

    /** portfolio_classification_counts — distinct customers per (group, value), ordered by group, count desc, value. */
    public function classificationCounts(): array
    {
        $g = [];
        foreach ($this->classificationValues() as $r) {
            $k = $r['group_key']."\0".$r['class_value'];
            $g[$k] ??= ['group_key' => $r['group_key'], 'group_order' => $r['group_order'], 'class_value' => $r['class_value'], 'nums' => []];
            $g[$k]['nums'][$r['customer_number']] = true;
        }
        $rows = array_map(fn ($x) => ['group_key' => $x['group_key'], 'group_order' => $x['group_order'], 'class_value' => $x['class_value'], 'customers' => count($x['nums'])], array_values($g));
        usort($rows, fn ($a, $b) => [$a['group_order'], -$a['customers'], $a['class_value']] <=> [$b['group_order'], -$b['customers'], $b['class_value']]);

        return $rows;
    }

    /** portfolio_classification_customers — the customers behind one card, with their current assignments for links. */
    public function classificationCustomers(string $group, string $classValue): array
    {
        if (! in_array($group, self::CLASSIFICATION_GROUPS, true)) {
            throw new \InvalidArgumentException('invalid group');
        }
        $rows = collect($this->classificationValues())->where('group_key', $group)->where('class_value', $classValue)
            ->unique('customer_number')->sortBy('customer_number')->values();

        return $this->withAssignments($rows->all());
    }

    /** Adds nullif(btrim(...)) rep_name / sales_owner / data_entry and the local customer page id. */
    private function withAssignments(array $rows): array
    {
        $ids = array_column($rows, 'customer_id');
        $raw = DB::table(self::CUSTOMERS)->whereIn('customer_id', $ids ?: [0])->get(['customer_id', 'rep_name', 'sales_owner', 'data_entry'])->keyBy(fn ($r) => (string) $r->customer_id);
        $local = DB::table('customers')->whereIn('zoho_contact_id', $ids ?: ['__none__'])->pluck('id', 'zoho_contact_id');
        $clean = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);

        return array_map(fn ($r) => $r + [
            'rep_name' => $clean($raw[$r['customer_id']]->rep_name ?? null),
            'sales_owner' => $clean($raw[$r['customer_id']]->sales_owner ?? null),
            'data_entry' => $clean($raw[$r['customer_id']]->data_entry ?? null),
            'local_id' => $local[$r['customer_id']] ?? null,
        ], $rows);
    }

    /* ───────────── Customer Actions (insights_* daily snapshot, see BuildInsightsSnapshot) ───────────── */

    /** Customer ids of the portfolio with a customer number (portfolio_action_counts / _customers membership rule). */
    private function actionPortfolio(): array
    {
        return DB::table(self::CUSTOMERS)->whereNotNull('customer_number')->where($this->scope, $this->value)
            ->pluck('customer_id')->map(fn ($v) => (string) $v)->all();
    }

    /** portfolio_action_counts — distinct customers per action in the active generation. */
    public function actionCounts(): array
    {
        $ag = DB::table('insights_active_generation')->first();
        $pf = $this->actionPortfolio();
        if (! $ag || ! $pf) {
            return [];
        }

        return DB::table('insights_customer_actions as s')
            ->join('insights_action_registry as r', 'r.action_code', '=', 's.action_code')
            ->where('s.generation_id', $ag->generation_id)->whereIn('s.customer_id', $pf)
            ->groupBy('r.category', 'r.category_order', 'r.action_code', 'r.action_order')
            ->orderBy('r.category_order')->orderBy('r.action_order')
            ->selectRaw('r.category, r.category_order, r.action_code, r.action_order, COUNT(DISTINCT s.customer_id) customers')
            ->get()->map(fn ($r) => [
                'generation_id' => (int) $ag->generation_id, 'published_at' => $ag->published_at, 'category' => $r->category,
                'category_order' => (int) $r->category_order, 'action_code' => $r->action_code, 'action_order' => (int) $r->action_order, 'customers' => (int) $r->customers,
            ])->all();
    }

    /** portfolio_action_snapshot_status — active / running generation and the latest source sync recorded at start. */
    public static function snapshotStatus(): array
    {
        $ag = DB::table('insights_active_generation')->first();
        $run = DB::table('insights_generations')->where('status', 'running')->first();
        $synced = null;
        if ($ag) {
            $src = json_decode((string) DB::table('insights_generations')->where('generation_id', $ag->generation_id)->value('source_at_start'), true);
            $synced = collect($src['datasets'] ?? [])->filter()->max();
        }

        return [
            'generation_id' => $ag ? (int) $ag->generation_id : null, 'published_at' => $ag->published_at ?? null,
            'running_generation_id' => $run ? (int) $run->generation_id : null,
            'running_progress' => $run ? $run->customers_done.'/'.$run->customers_total : null,
            'source_synced_at' => $synced,
        ];
    }

    /** portfolio_action_customers — customers of one action in a given complete generation (generation_available=false if retired). */
    public function actionCustomers(string $action, int $generationId): array
    {
        if (! DB::table('insights_action_registry')->where('action_code', $action)->exists()) {
            throw new \InvalidArgumentException('invalid action');
        }
        $pub = DB::table('insights_generations')->where('generation_id', $generationId)->where('status', 'complete')->value('published_at');
        if ($pub === null) {
            return ['generation_available' => false, 'rows' => []];
        }
        $rows = DB::table('insights_customer_actions as s')
            ->join(self::CUSTOMERS.' as c', 'c.customer_id', '=', 's.customer_id')
            ->where('s.generation_id', $generationId)->where('s.action_code', $action)
            ->whereNotNull('c.customer_number')->where("c.{$this->scope}", $this->value)
            ->orderBy('c.customer_number')
            ->get(['c.customer_id', 'c.customer_number', 'c.customer_name'])
            ->map(fn ($r) => ['customer_id' => (string) $r->customer_id, 'customer_number' => $r->customer_number, 'customer_name' => $r->customer_name])->all();

        return ['generation_available' => true, 'published_at' => $pub, 'rows' => $this->withAssignments($rows)];
    }
}
