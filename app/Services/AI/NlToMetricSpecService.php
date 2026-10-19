<?php

namespace App\Services\AI;

use App\DTOs\MetricSpec;
use App\Services\Stats\MetricCatalog;
use InvalidArgumentException;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Maps a natural-language question onto the fixed metric catalog via
 * constrained function-calling. The model never writes SQL and never
 * invents a metric — it can only select from what MetricCatalog exposes.
 * If the question doesn't map cleanly, resolve() returns null and the
 * caller falls back to grounded advisory mode.
 */
class NlToMetricSpecService
{
    use RetriesTransientOpenAiErrors;

    public function __construct(private readonly MetricCatalog $catalog) {}

    public function resolve(string $question): ?MetricSpec
    {
        $response = $this->withOpenAiRetry(fn () => OpenAI::chat()->create([
            'model' => config('openai.model'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You turn business questions (inventory, sales, purchase orders, returns, or '.
                        'customer accounts receivable/credit) into a compute_metric tool call, but ONLY when '.
                        'the question maps cleanly onto one of the available metrics, dimensions, and filters. '.
                        'A question naming a specific customer by name or number should use the customer_name or '.
                        'customer_number filter with that exact text. '.
                        'If the question asks for advice, opinion, prediction, or anything not directly computable '.
                        'from the catalog, do NOT call the tool — just reply with the word NONE.',
                ],
                ['role' => 'user', 'content' => $question],
            ],
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'compute_metric',
                    'description' => 'Compute a specific business metric (inventory, sales, purchase orders, returns, or customer AR/credit) from structured data.',
                    'parameters' => $this->catalog->toToolSchema(),
                ],
            ]],
        ]));

        $toolCalls = $response->choices[0]->message->toolCalls ?? [];
        if (empty($toolCalls)) {
            return null;
        }

        $args = json_decode($toolCalls[0]->function->arguments, true);
        if (! is_array($args)) {
            return null;
        }

        try {
            return $this->catalog->buildSpec($args);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
