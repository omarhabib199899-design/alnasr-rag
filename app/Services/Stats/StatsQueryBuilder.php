<?php

namespace App\Services\Stats;

use App\DTOs\MetricSpec;
use App\Models\ArLedgerEntry;
use App\Models\CustomerCreditUsage;
use App\Models\InventoryItem;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnItem;
use App\Models\SaleItem;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The one and only place a MetricSpec turns into a real query. Used by the
 * fixed dashboard and, later, by chat/dynamic stats — same engine either
 * way, so "compute, don't guess" holds everywhere a number is shown.
 */
class StatsQueryBuilder
{
    private const SUBJECT_MODELS = [
        'inventory' => InventoryItem::class,
        'sales' => SaleItem::class,
        'purchase_orders' => PurchaseOrderItem::class,
        'returns' => ReturnItem::class,
        'customer_ledger' => ArLedgerEntry::class,
        'customer_credit' => CustomerCreditUsage::class,
    ];

    /**
     * Subjects scoped to one user's own uploaded/synced Import snapshot(s)
     * — see CurrentInventory. customer_ledger/customer_credit are
     * company-wide live tables fed by a single scheduled sync job, not tied
     * to any one user's import at all (see project_customer360_tenant_gap_
     * pre_sale memory) — $importIds is meaningless for them and ignored.
     */
    private const IMPORT_SCOPED_SUBJECTS = ['inventory', 'sales', 'purchase_orders', 'returns'];

    public function __construct(private readonly MetricCatalog $catalog) {}

    /**
     * @param  array<int, int>  $importIds  every Import row whose rows count as "current" for this metric's subject
     *                                       (see CurrentInventory::relevantImportIds — usually 1 id for Excel/inventory
     *                                       data, but many for an incrementally Zoho-synced transaction type)
     */
    public function run(MetricSpec $spec, array $importIds): array
    {
        $definition = $this->catalog->metricDefinition($spec->metric);
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown metric: {$spec->metric}");
        }

        // Confirmed live (2026-10-15) the dashboard was taking 30+ seconds
        // to load: sales/purchase_orders/returns metrics run a GROUP BY or
        // SUM over sale_items/purchase_order_items/return_items with no
        // narrowing predicate beyond import_id IN ($importIds) — and since
        // this app is single-tenant today (see project_multitenancy_gap
        // memory), that list already covers virtually every row in these
        // 1.3M+/1.6M-row tables, so MySQL correctly picks a full table scan
        // over the import_id index (confirmed via EXPLAIN — not a missing-
        // index bug, the predicate just isn't selective). Same root cause
        // ReportsController::transactions() already hit and fixed the same
        // way: these numbers don't need to be accurate to the second, so a
        // short cache turns a 10-30s scan into a sub-millisecond hit for
        // every visitor after the first. Keyed on everything the result
        // actually depends on, so a stale key is structurally impossible —
        // metric/groupBy/filters/topN change the query shape, and importIds
        // changes whose rows get selected into it.
        $cacheKey = 'stats.run.'.md5(json_encode([
            $spec->metric, $spec->groupBy, $spec->filters, $spec->topN, $importIds,
        ]));

        return Cache::remember($cacheKey, now()->addMinutes(5), fn () => match ($definition['type']) {
            'aggregate' => $this->runAggregate($spec, $definition, $importIds),
            'listing' => $this->runListing($spec, $definition, $importIds),
            default => throw new InvalidArgumentException("Unknown metric type: {$definition['type']}"),
        });
    }

    private function baseQuery(MetricSpec $spec, array $definition, array $importIds)
    {
        $model = self::SUBJECT_MODELS[$definition['subject']] ?? throw new InvalidArgumentException("Unknown subject: {$definition['subject']}");
        $query = $model::query();

        if (in_array($definition['subject'], self::IMPORT_SCOPED_SUBJECTS, true)) {
            $query->whereIn('import_id', $importIds);
        }

        foreach ($spec->filters as $filterKey => $value) {
            $filterDef = $this->catalog->filters()[$filterKey];
            if (($filterDef['match'] ?? 'exact') === 'contains') {
                $query->where($filterDef['column'], 'like', '%'.$value.'%');
            } else {
                $query->where($filterDef['column'], $value);
            }
        }

        return $query;
    }

    private function runAggregate(MetricSpec $spec, array $definition, array $importIds): array
    {
        $query = $this->baseQuery($spec, $definition, $importIds);

        foreach (($definition['implicit_filters'] ?? []) as $key => $value) {
            if (str_ends_with($key, '_eq')) {
                $query->where(str_replace('_eq', '', $key), $value);
            } elseif (str_ends_with($key, '_ne')) {
                $query->where(str_replace('_ne', '', $key), '!=', $value);
            }
        }

        $selectExpr = match ($definition['aggregation']) {
            'sum' => "SUM({$definition['column']})",
            'avg' => "AVG({$definition['column']})",
            'count' => 'COUNT(*)',
            'sum_expression' => "SUM({$definition['expression']})",
            default => throw new InvalidArgumentException("Unknown aggregation: {$definition['aggregation']}"),
        };

        if ($spec->groupBy !== null) {
            $dimension = $this->catalog->dimensions()[$spec->groupBy];
            $groupExpr = $dimension['expression'] ?? $dimension['column'];

            // Rows where this dimension is null either get their own bucket
            // (null_label set — e.g. "Uncategorized") or get excluded from
            // the breakdown entirely (no null_label — the original
            // behavior, still right for dimensions that are effectively
            // always populated).
            $nullLabel = $dimension['null_label'] ?? null;
            $labelExpr = $nullLabel !== null
                ? "COALESCE({$groupExpr}, '".addslashes($nullLabel)."')"
                : $groupExpr;

            $rows = $query->selectRaw("{$labelExpr} as label, {$selectExpr} as value");

            if (isset($dimension['column']) && $nullLabel === null) {
                $rows->whereNotNull($dimension['column']);
            }

            $rows->groupByRaw($groupExpr);

            $rows = ($dimension['sort'] ?? 'value_desc') === 'label_asc'
                ? $rows->orderBy('label')
                : $rows->orderByDesc('value');

            if ($spec->topN !== null) {
                $rows->limit($spec->topN);
            }

            $rows = $rows->get();

            return [
                'type' => 'breakdown',
                'label' => $definition['label'],
                'group_by' => $spec->groupBy,
                'rows' => $rows->map(fn ($r) => ['label' => $r->label, 'value' => (float) $r->value])->all(),
            ];
        }

        $value = $query->selectRaw("{$selectExpr} as value")->value('value');

        return [
            'type' => 'scalar',
            'label' => $definition['label'],
            'value' => (float) $value,
        ];
    }

    private function runListing(MetricSpec $spec, array $definition, array $importIds): array
    {
        $query = $this->baseQuery($spec, $definition, $importIds);

        if ($definition['exclude_zero_stock'] ?? false) {
            $query->where('stock', '>', 0);
        }

        if (isset($definition['sort_expression'])) {
            $query->orderByRaw("({$definition['sort_expression']}) {$definition['sort_direction']}");
        } else {
            $query->orderBy($definition['sort_column'], $definition['sort_direction']);
        }

        $limit = $spec->topN ?? 10;
        $rows = $query->limit($limit)->get(['sku', 'name', 'stock', 'price', 'category', 'warehouse']);

        return [
            'type' => 'listing',
            'label' => $definition['label'],
            'rows' => $rows->map(fn ($item) => [
                'sku' => $item->sku,
                'name' => $item->name,
                'stock' => $item->stock,
                'price' => (float) $item->price,
                'category' => $item->category,
                'warehouse' => $item->warehouse,
            ])->all(),
        ];
    }
}
