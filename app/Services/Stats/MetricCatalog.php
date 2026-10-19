<?php

namespace App\Services\Stats;

use App\DTOs\MetricSpec;
use InvalidArgumentException;

class MetricCatalog
{
    public function metrics(): array
    {
        return config('metrics.metrics');
    }

    public function dimensions(): array
    {
        return config('metrics.dimensions');
    }

    public function filters(): array
    {
        return config('metrics.filters');
    }

    public function metricDefinition(string $metric): ?array
    {
        return $this->metrics()[$metric] ?? null;
    }

    public function isValidMetric(string $metric): bool
    {
        return array_key_exists($metric, $this->metrics());
    }

    public function isValidDimension(string $dimension): bool
    {
        return array_key_exists($dimension, $this->dimensions());
    }

    public function isValidFilter(string $filter): bool
    {
        return array_key_exists($filter, $this->filters());
    }

    /**
     * Build and validate a MetricSpec from raw input (form data, or later,
     * an LLM tool call's arguments). Throws if anything falls outside the
     * catalog — callers should catch this and respond "can't compute that"
     * rather than letting anything unvalidated reach the query builder.
     */
    public function buildSpec(array $raw): MetricSpec
    {
        $metric = $raw['metric'] ?? null;
        if (! is_string($metric) || ! $this->isValidMetric($metric)) {
            throw new InvalidArgumentException("Unknown metric: {$metric}");
        }

        $definition = $this->metricDefinition($metric);
        $subject = $definition['subject'];

        $groupBy = $raw['group_by'] ?? $definition['default_group_by'] ?? null;
        if ($groupBy !== null) {
            if (! $this->isValidDimension($groupBy)) {
                throw new InvalidArgumentException("Unknown dimension: {$groupBy}");
            }
            if (! $this->subjectAllowed($this->dimensions()[$groupBy]['subject'], $subject)) {
                throw new InvalidArgumentException("Dimension {$groupBy} does not apply to {$metric}");
            }
        }

        $filters = [];
        foreach (($raw['filters'] ?? []) as $key => $value) {
            if (! $this->isValidFilter($key)) {
                throw new InvalidArgumentException("Unknown filter: {$key}");
            }
            if (! $this->subjectAllowed($this->filters()[$key]['subject'], $subject)) {
                throw new InvalidArgumentException("Filter {$key} does not apply to {$metric}");
            }
            $filters[$key] = (string) $value;
        }

        $topN = isset($raw['top_n']) ? (int) $raw['top_n'] : ($definition['default_top_n'] ?? null);

        return new MetricSpec(
            metric: $metric,
            groupBy: $groupBy,
            filters: $filters,
            topN: $topN,
        );
    }

    /**
     * @param  string|string[]  $allowed  a subject, an array of subjects, or 'any'
     */
    private function subjectAllowed(string|array $allowed, string $subject): bool
    {
        if ($allowed === 'any') {
            return true;
        }

        return is_array($allowed) ? in_array($subject, $allowed, true) : $allowed === $subject;
    }

    /**
     * The catalog expressed as an OpenAI function-calling parameter schema,
     * so the LLM can only ever select from what's actually computable.
     */
    public function toToolSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric' => [
                    'type' => 'string',
                    'enum' => array_keys($this->metrics()),
                    'description' => collect($this->metrics())
                        ->map(fn ($def, $key) => "{$key}: {$def['label']}")
                        ->implode('; '),
                ],
                'group_by' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge(array_keys($this->dimensions()), [null]),
                ],
                'filters' => [
                    'type' => 'object',
                    'properties' => collect($this->filters())
                        ->mapWithKeys(fn ($def, $key) => [$key => ['type' => 'string']])
                        ->all(),
                ],
                'top_n' => ['type' => ['integer', 'null']],
            ],
            'required' => ['metric'],
        ];
    }
}
