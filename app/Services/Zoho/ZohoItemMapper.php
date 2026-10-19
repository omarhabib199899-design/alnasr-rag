<?php

namespace App\Services\Zoho;

use Illuminate\Support\Carbon;

/**
 * Pure translation from one raw Zoho Inventory item object to the row shape
 * InventoryRowsPersister expects. Fixed/code-defined mapping — unlike the
 * Excel path there's no user-configurable column mapping, since the source
 * is a stable API schema rather than an arbitrary spreadsheet.
 *
 * An item with multi-warehouse tracking enabled reports stock per location
 * under `locations` (location_id/location_name/location_stock_on_hand per
 * Zoho's docs) — that produces one inventory_items row per warehouse, all
 * sharing the same sku/name/price/cost/category. An item without location
 * tracking has no `locations` array at all and produces a single row with
 * `warehouse: null`, using the flat stock fields as a fallback.
 */
class ZohoItemMapper
{
    /**
     * @param  array<string, mixed>  $zohoItem
     * @param  ?int  $stockOverride  current stock-on-hand resolved from the
     *     Zoho Analytics "INVENTORY DATA" bulk export (see
     *     SyncZohoInventoryJob), used instead of $zohoItem's own stock
     *     fields — the items LIST endpoint this is now built from doesn't
     *     carry stock at all (see this method's stale flat-stock fallback
     *     below, kept only for any caller still passing a DETAIL response).
     *     Falls back to resolveFlatStock() when null so a caller that still
     *     has a real per-item detail response (none currently do) keeps
     *     working unchanged.
     * @return array<int, array<string, mixed>> one row per warehouse, or one row if untracked
     */
    public function mapToRows(array $zohoItem, int $importId, Carbon $now, ?int $stockOverride = null): array
    {
        // SFDA compliance fields — the items LIST endpoint flattens custom
        // fields as top-level cf_* keys (confirmed live via the org's own
        // Deluge SFDA price-check script, which reads item.get("cf_sfda_no")
        // /item.get("cf_rsp") straight from that response). Since 2026-09-23
        // this mapper receives the per-item DETAIL response instead (the
        // list endpoint stopped including stock — see ZohoInventoryClient::
        // fetchItemDetail()'s docblock), whose custom fields live under
        // custom_field_hash instead — checking both keeps this working
        // regardless of which shape actually reaches it.
        $customFields = $zohoItem['custom_field_hash'] ?? [];
        $sfdaRegNo = $zohoItem['cf_sfda_no'] ?? $customFields['cf_sfda_no'] ?? null;
        $rsp = $zohoItem['cf_rsp'] ?? $customFields['cf_rsp'] ?? null;
        // "OWNER" (the Purchaser responsible for this item's SKUs, per Dr.
        // Ahmed voice note 2026-09-27) — following the exact same cf_*
        // naming convention already proven for cf_sfda_no/cf_rsp above (a
        // manual Analytics export confirmed the field is literally named
        // "OWNER"), not yet confirmed against a live custom_field_hash
        // response the way those two were — flag/recheck if this ever comes
        // back consistently null once the sync actually runs.
        $owner = $zohoItem['cf_owner'] ?? $customFields['cf_owner'] ?? null;

        $base = [
            'import_id' => $importId,
            'sku' => trim((string) ($zohoItem['sku'] ?? '')),
            'name' => trim((string) ($zohoItem['name'] ?? '')),
            'price' => (float) ($zohoItem['rate'] ?? 0),
            'cost' => isset($zohoItem['purchase_rate']) ? (float) $zohoItem['purchase_rate'] : null,
            'category' => $zohoItem['category_name'] ?? $zohoItem['group_name'] ?? null,
            'extra' => json_encode([
                'zoho_item_id' => $zohoItem['item_id'] ?? null,
                'unit' => $zohoItem['unit'] ?? null,
                // cf_sfda_no is the drug's SFDA registration number; cf_rsp
                // is our recorded regulated selling price — both feed the
                // SFDA official-price comparison report.
                'sfda_reg_no' => $sfdaRegNo,
                'rsp' => $rsp,
                'owner' => $owner,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Summed into ONE row rather than fanning out per-warehouse (despite
        // this class's own docblock describing a per-warehouse row shape) —
        // confirmed live 2026-09-23 that fanning out here inflates the
        // dashboard's item_count/stockout_count metrics ~10x (this org has
        // 10 tracked locations per item), since those metrics assume one row
        // per item, matching how the per-warehouse breakdown has always
        // actually been sourced from a separate table (inventory_warehouse_
        // stock, via the manual Stock Summary Report import) rather than
        // this one. Total stock/value stay correct either way since they're
        // sums, but item_count/stockout_count are per-row counts.
        if ($stockOverride !== null) {
            return [array_merge($base, [
                'stock' => $stockOverride,
                'warehouse' => null,
            ])];
        }

        if (! empty($zohoItem['locations']) && is_array($zohoItem['locations'])) {
            $totalStock = array_sum(array_column($zohoItem['locations'], 'location_stock_on_hand'));

            return [array_merge($base, [
                'stock' => (int) $totalStock,
                'warehouse' => null,
            ])];
        }

        return [array_merge($base, [
            'stock' => $this->resolveFlatStock($zohoItem),
            'warehouse' => null,
        ])];
    }

    /**
     * Exposed separately (not just buried in mapToRows' extra json) so
     * SyncZohoInventoryJob can upsert item_owners — a dedicated table, kept
     * independent of inventory_items' own generation-swap/delete lifecycle
     * (see that table's migration) — from the same already-fetched detail
     * response, no extra API call.
     */
    public function extractOwner(array $zohoItem): ?string
    {
        $customFields = $zohoItem['custom_field_hash'] ?? [];
        $owner = $zohoItem['cf_owner'] ?? $customFields['cf_owner'] ?? null;

        return $owner !== null ? trim((string) $owner) ?: null : null;
    }

    private function resolveFlatStock(array $zohoItem): int
    {
        if (isset($zohoItem['stock_on_hand'])) {
            return (int) $zohoItem['stock_on_hand'];
        }

        if (isset($zohoItem['available_stock'])) {
            return (int) $zohoItem['available_stock'];
        }

        return 0;
    }
}
