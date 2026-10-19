<?php

namespace App\DTOs;

/**
 * A validated request for a single computed stat: which metric, optionally
 * grouped by a dimension, optionally filtered, optionally limited to a
 * top-N. This is the only shape a stat query can take — it's built either
 * by the fixed dashboard or (later) by constrained LLM tool-calling, never
 * from raw SQL.
 */
class MetricSpec
{
    /**
     * @param  array<string, string>  $filters  dimension key => value
     */
    public function __construct(
        public readonly string $metric,
        public readonly ?string $groupBy = null,
        public readonly array $filters = [],
        public readonly ?int $topN = null,
    ) {}
}
