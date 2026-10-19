<?php

namespace App\Http\Controllers;

use App\Models\DashboardWidget;
use App\Models\Import;
use App\Models\ZohoConnection;
use App\Services\Stats\CurrentInventory;
use App\Services\Stats\MetricCatalog;
use App\Services\Stats\StatsQueryBuilder;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentInventory $currentInventory,
        private readonly MetricCatalog $catalog,
        private readonly StatsQueryBuilder $stats,
    ) {}

    public function index()
    {
        $userId = auth()->id();
        $importId = $this->currentInventory->currentInventoryImportId($userId);
        // The company's Zoho organization currency (e.g. "SAR") — see
        // ZohoInventoryClient::resolveOrganizationId()'s docblock for where
        // it's captured. Not scoped to $userId (fixed 2026-10-14, same
        // reasoning as CurrentInventory::EXCLUDED_FOREIGN_TENANT_USER_ID —
        // a brand-new user has no ZohoConnection row of their own, so this
        // silently fell back to 'SAR' for them instead of showing the real
        // connected company's currency; harmless today only because that
        // fallback happens to already be correct for Al Nasr). Falls back to
        // 'SAR' only for a connection synced before 2026-10-04 that hasn't
        // re-resolved its organization since (resolveOrganizationId()
        // short-circuits once zoho_organization_id is cached, so it won't
        // backfill this on its own) — every company actually on this system
        // today bills in SAR, so the fallback isn't currently hiding a
        // wrong-currency case for anyone.
        $currencySymbol = ZohoConnection::where('user_id', '!=', CurrentInventory::excludedForeignTenantUserId())
            ->where('status', 'connected')
            ->value('currency_symbol') ?? 'SAR';
        $salesImportIds = $this->currentInventory->relevantImportIds($userId, 'sales');
        $purchaseImportIds = $this->currentInventory->relevantImportIds($userId, 'purchase_orders');
        $returnsImportIds = $this->currentInventory->relevantImportIds($userId, 'returns');

        // "Last synced" shown on the dashboard — the most recent update
        // timestamp across every import this user's own data actually came
        // from, scoped by the same already-tenant-safe id lists above (not
        // a fresh org-wide query) so this can't leak another company's sync
        // activity the way the pre-2026-09-30 Dashboard bug did.
        $allImportIds = array_unique(array_merge(
            $importId !== null ? [$importId] : [],
            $salesImportIds,
            $purchaseImportIds,
            $returnsImportIds,
        ));
        $lastSyncedRaw = empty($allImportIds)
            ? null
            : Import::whereIn('id', $allImportIds)->where('status', 'completed')->max('updated_at');
        $lastSyncedAt = $lastSyncedRaw !== null ? \Illuminate\Support\Carbon::parse($lastSyncedRaw) : null;

        if ($importId === null && empty($salesImportIds) && empty($purchaseImportIds) && empty($returnsImportIds)) {
            return view('dashboard', [
                'hasData' => false, 'hasInventory' => false, 'hasSales' => false,
                'hasPurchases' => false, 'hasReturns' => false, 'lastSyncedAt' => $lastSyncedAt,
                'currencySymbol' => $currencySymbol,
            ]);
        }

        $data = [
            'hasData' => true,
            'hasInventory' => $importId !== null,
            'hasSales' => ! empty($salesImportIds),
            'hasPurchases' => ! empty($purchaseImportIds),
            'hasReturns' => ! empty($returnsImportIds),
            'lastSyncedAt' => $lastSyncedAt,
            'currencySymbol' => $currencySymbol,
        ];

        if ($importId !== null) {
            $data['kpis'] = [
                'total_stock' => $this->run('total_stock', [$importId]),
                'total_stock_value' => $this->run('total_stock_value', [$importId]),
                'item_count' => $this->run('item_count', [$importId]),
                'stockout_count' => $this->run('stockout_count', [$importId]),
            ];
            $data['categoryBreakdown'] = $this->run('total_stock_value', [$importId], groupBy: 'category');
            $data['warehouseBreakdown'] = $this->run('total_stock_value', [$importId], groupBy: 'warehouse');
            $data['itemCountByCategory'] = $this->run('item_count', [$importId], groupBy: 'category');
            $data['stockoutsByCategory'] = $this->run('stockout_count', [$importId], groupBy: 'category');
            $data['lowestStock'] = $this->run('lowest_stock_items', [$importId], topN: 10);
            $data['highestValue'] = $this->run('highest_value_items', [$importId], topN: 10);
        }

        if (! empty($salesImportIds)) {
            $data['salesKpis'] = [
                'total_quantity_ordered' => $this->run('total_quantity_ordered', $salesImportIds),
                'cancelled_orders_count' => $this->run('cancelled_orders_count', $salesImportIds),
            ];
            $data['mostOrdered'] = $this->run('most_ordered_products', $salesImportIds);
            $data['mostCancelled'] = $this->run('most_cancelled_products', $salesImportIds);
            $data['ordersByMonth'] = $this->run('orders_by_month', $salesImportIds);
        }

        if (! empty($purchaseImportIds)) {
            $data['purchaseKpis'] = [
                'total_quantity_purchased' => $this->run('total_quantity_purchased', $purchaseImportIds),
                'pending_purchase_orders_count' => $this->run('pending_purchase_orders_count', $purchaseImportIds),
            ];
            $data['mostPurchased'] = $this->run('most_purchased_products', $purchaseImportIds);
            $data['purchasesByMonth'] = $this->run('purchases_by_month', $purchaseImportIds);
        }

        if (! empty($returnsImportIds)) {
            $data['returnsKpis'] = [
                'total_units_returned' => $this->run('total_units_returned', $returnsImportIds),
            ];
            $data['mostReturned'] = $this->run('most_returned_products', $returnsImportIds);
            $data['returnsByMonth'] = $this->run('returns_by_month', $returnsImportIds);
            $data['returnsByReason'] = $this->run('returns_by_reason', $returnsImportIds);
        }

        $data['pinnedWidgets'] = $this->runPinnedWidgets([
            'inventory' => $importId !== null ? [$importId] : [],
            'sales' => $salesImportIds,
            'purchase_orders' => $purchaseImportIds,
            'returns' => $returnsImportIds,
        ]);

        return view('dashboard', $data);
    }

    private function run(string $metric, array $importIds, ?string $groupBy = null, ?int $topN = null): array
    {
        $spec = $this->catalog->buildSpec([
            'metric' => $metric,
            'group_by' => $groupBy,
            'top_n' => $topN,
        ]);

        return $this->stats->run($spec, $importIds);
    }

    /**
     * Every pinned widget recomputes live from its stored spec — nothing
     * frozen. A widget whose spec no longer validates (e.g. the catalog
     * changed), or whose subject's data isn't uploaded, is skipped rather
     * than breaking the whole dashboard.
     *
     * @param  array<string, array<int, int>>  $importIds  subject => relevant import ids
     */
    private function runPinnedWidgets(array $importIds): array
    {
        $widgets = DashboardWidget::where('user_id', auth()->id())->latest()->get();

        $results = [];
        foreach ($widgets as $widget) {
            try {
                $spec = $this->catalog->buildSpec($widget->metric_spec);
                $definition = $this->catalog->metricDefinition($spec->metric);
                $targetImportIds = $importIds[$definition['subject']] ?? [];

                if (empty($targetImportIds)) {
                    continue;
                }

                $result = $this->stats->run($spec, $targetImportIds);
                $results[] = [
                    'widget' => $widget,
                    'result' => $result,
                ];
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $results;
    }
}
