<?php

namespace App\Services\Reports;

use App\Models\InventoryItem;
use App\Models\InventoryWarehouseStock;
use App\Models\InvoiceItem;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRecommendation;
use App\Services\Stats\CurrentInventory;
use Illuminate\Support\Collection;

/**
 * Computes the Purchase Recommendation report: per-SKU stock status,
 * suggested purchase quantity, and vendor comparison (lowest price, best
 * discount, most purchased from).
 *
 * The thresholds below (CRITICAL/LOW/OVERSTOCK day bands, target coverage)
 * are provisional defaults, not a confirmed business rule — the manager's
 * WhatsApp message named the *factors* that matter (6-month movement,
 * profit, customer count, stockout frequency) but not a precise formula.
 * These numbers are deliberately isolated as constants so they're easy to
 * tune once he reviews real output, rather than guessed and buried in logic.
 *
 * "Pending PO" filters on status 'open'/'issued' — Zoho's exact purchase
 * order status vocabulary for this org hasn't been empirically confirmed
 * (only seen "ISSUED"/"CLOSED" in a UI screenshot), unlike everything else
 * this sync verified against live data today. Worth a quick check before
 * trusting that column.
 */
class PurchaseRecommendationBuilder
{
    private const CRITICAL_DAYS = 15;

    private const LOW_DAYS = 30;

    private const OVERSTOCK_DAYS = 90;

    private const TARGET_COVERAGE_DAYS = 60;

    private const VELOCITY_WINDOW_DAYS = 182; // ~6 months

    public function __construct(
        private readonly CurrentInventory $currentInventory,
        private readonly StockoutHistoryEstimator $stockoutEstimator,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(int $userId): Collection
    {
        $importId = $this->currentInventory->currentInventoryImportId($userId);
        if ($importId === null) {
            return collect();
        }

        $sinceDate = now()->subDays(self::VELOCITY_WINDOW_DAYS)->toDateString();

        // Sourced from invoice_items (Invoices), not sale_items (Sales
        // Orders): an invoice is the confirmed, billed sale — a sales order
        // can sit in Draft or get cancelled without ever moving stock, which
        // made sale_items a noisier proxy for "actually sold". Invoices are
        // also the only one of the two with a practical manual-export
        // backfill path (Zoho's "Stock Movement" report, since neither
        // "Sales by Item" (aggregate-only) nor "Sales Order Details"
        // (document-level, no SKU) exposes per-line Sales Order data) — so
        // sale_items stays perpetually behind the slow per-document API sync
        // while invoice_items can be fully backfilled in one sitting.
        $salesBySku = InvoiceItem::where('invoice_date', '>=', $sinceDate)
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku');

        $pendingPoBySku = PurchaseOrderItem::whereIn('status', ['open', 'issued'])
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku');

        $vendorRowsBySku = PurchaseOrderItem::whereNotNull('vendor_name')
            ->selectRaw('sku, vendor_name, SUM(quantity) as total_qty, MIN(rate) as min_rate, MAX(discount_percent) as max_discount')
            ->groupBy('sku', 'vendor_name')
            ->get()
            ->groupBy('sku');

        // customer_name only exists on rows synced (or re-synced) after the
        // Zoho mapper started capturing it, or imported from a report that
        // included a contact column — older still-unsynced rows have it
        // null, so these two are undercounts until that backfill catches
        // up, not a bug in this query itself.
        $customerCountBySku = InvoiceItem::where('invoice_date', '>=', $sinceDate)
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('customer_name')
            ->selectRaw('sku, COUNT(DISTINCT customer_name) as cnt')
            ->groupBy('sku')
            ->pluck('cnt', 'sku');

        $customerSales = InvoiceItem::where('invoice_date', '>=', $sinceDate)
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('customer_name')
            ->select('sku', 'customer_name', 'quantity')
            ->get();

        $topOrderBySku = $customerSales->groupBy('sku')
            ->map(fn (Collection $rows) => $rows->sortByDesc('quantity')->first());

        // No customer-type field is synced from Zoho at all (would need the
        // Customers module, not currently pulled) — this is a name-pattern
        // stand-in the manager can sanity-check, not an official
        // classification. Al-Nasr's customers overwhelmingly name pharmacies
        // starting with "صيدلية" (and occasionally the English "pharmacy"),
        // vs. "شركة"/"مؤسسة"/"مستودع" for wholesale/warehouse accounts.
        $topPharmacyOrderBySku = $customerSales
            ->filter(fn ($row) => self::looksLikePharmacy($row->customer_name))
            ->groupBy('sku')
            ->map(fn (Collection $rows) => $rows->sortByDesc('quantity')->first());

        $items = InventoryItem::where('import_id', $importId)->get();

        // Grouped/summed rather than a plain sku => item map in case an SKU
        // has more than one InventoryItem row (multi-warehouse tracking) —
        // the estimator needs one current-stock number per SKU, matching
        // what buildRow() below sums the same way if that ever applies.
        $currentStockBySku = $items->groupBy('sku')->map(fn (Collection $rows) => (int) $rows->sum('stock'));
        $stockoutCountBySku = $this->stockoutEstimator->estimate($currentStockBySku);

        // Manually imported per-branch data (ImportWarehouseStockReport) —
        // separate from the live API sync's always-null 'warehouse' column
        // above, since Zoho's list-endpoint items don't carry locations.
        // Summarized to the single highest-stock branch per SKU, the same
        // "top X" shape as the customer/pharmacy columns, since a full
        // per-branch breakdown doesn't fit one row per SKU.
        $warehouseRowsBySku = InventoryWarehouseStock::where('closing_stock', '>', 0)
            ->get(['sku', 'warehouse', 'closing_stock'])
            ->groupBy('sku');

        // Per-branch demand, for the transfer-suggestion columns below —
        // separate from $salesBySku (company-wide total). Needs
        // invoice_items.warehouse, only populated from 2026-09-12 onward
        // (see ImportStockMovementReport's docblock on the duplicate "From
        // Location Name" column) — a branch with real sales this window
        // that's missing here just means no invoice for that SKU there
        // happened to fall in the re-parsed data, not that the branch
        // doesn't sell it.
        $warehouseVelocityBySku = InvoiceItem::where('invoice_date', '>=', $sinceDate)
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('warehouse')
            ->selectRaw('sku, warehouse, SUM(quantity) as qty')
            ->groupBy('sku', 'warehouse')
            ->get()
            ->groupBy('sku');

        // reports:import-expiry-report owns this column — it writes straight
        // to purchase_recommendations independently of this build, since
        // Zoho's expiry data comes from its own custom module, not anything
        // synced here. Reading the current value back and re-including it
        // below (rather than leaving it out, or hardcoding null) means
        // persistLocally()'s upsert re-writes the same value instead of
        // erasing it, while --push still sees and sends it like every other
        // field — the array a row is built into is the only thing both the
        // local upsert and the Zoho push read from.
        $expiredQtyBySku = PurchaseRecommendation::pluck('expired_quantity', 'sku');

        return $items->map(fn (InventoryItem $item) => $this->buildRow(
            $item, $salesBySku, $pendingPoBySku, $vendorRowsBySku, $customerCountBySku, $topOrderBySku, $topPharmacyOrderBySku, $stockoutCountBySku, $warehouseRowsBySku, $expiredQtyBySku, $warehouseVelocityBySku
        ));
    }

    /**
     * Name-pattern heuristic, not a real customer classification — see the
     * comment where this is used. Case-insensitive so an English "Pharmacy"
     * in a name still matches.
     */
    private static function looksLikePharmacy(string $customerName): bool
    {
        return str_contains($customerName, 'صيدلية') || stripos($customerName, 'pharm') !== false;
    }

    /**
     * @param  Collection<string, int>  $salesBySku
     * @param  Collection<string, int>  $pendingPoBySku
     * @param  Collection<string, Collection>  $vendorRowsBySku
     * @param  Collection<string, int>  $customerCountBySku
     * @param  Collection<string, InvoiceItem>  $topOrderBySku
     * @param  Collection<string, InvoiceItem>  $topPharmacyOrderBySku
     * @param  Collection<string, int>  $stockoutCountBySku
     * @param  Collection<string, Collection>  $warehouseRowsBySku
     * @param  Collection<string, ?int>  $expiredQtyBySku
     * @param  Collection<string, Collection>  $warehouseVelocityBySku
     * @return array<string, mixed>
     */
    private function buildRow(
        InventoryItem $item,
        Collection $salesBySku,
        Collection $pendingPoBySku,
        Collection $vendorRowsBySku,
        Collection $customerCountBySku,
        Collection $topOrderBySku,
        Collection $topPharmacyOrderBySku,
        Collection $stockoutCountBySku,
        Collection $warehouseRowsBySku,
        Collection $expiredQtyBySku,
        Collection $warehouseVelocityBySku,
    ): array {
        $sku = $item->sku;
        $sold6mo = (int) ($salesBySku[$sku] ?? 0);
        $pendingPo = (int) ($pendingPoBySku[$sku] ?? 0);
        $stock = (int) $item->stock;
        $totalAvailable = $stock + $pendingPo;
        $dailyVelocity = $sold6mo / self::VELOCITY_WINDOW_DAYS;

        [$status, $suggestedPurchase] = $this->classify($stock, $sold6mo, $totalAvailable, $dailyVelocity);

        $vendors = $vendorRowsBySku->get($sku, collect());
        $mostPurchased = $vendors->sortByDesc('total_qty')->first();
        $lowestPrice = $vendors->sortBy('min_rate')->first();
        $bestDiscount = $vendors->sortByDesc('max_discount')->first();

        $price = (float) $item->price;

        // Zoho's own item.cost (purchase_rate) is unreliable for this org —
        // confirmed live: it's a flat 1.00 on ~4,300 of 6,287 items
        // regardless of the item's actual price (one sample: price 2,955.80
        // SAR, cost 1.00 SAR), clearly an unfilled placeholder rather than
        // real cost data. Real purchase rates already exist per-vendor on
        // purchase_order_items (the same data the vendor-comparison columns
        // below use) — prefer the lowest one actually paid as the cost
        // basis. The manager's explicit call for a SKU with no PO history at
        // all: don't guess with Zoho's unreliable item.cost — just use the
        // selling price as cost, so profit reads as 0 (an honest "unknown"),
        // not a fabricated number.
        $realCost = $lowestPrice?->min_rate !== null ? (float) $lowestPrice->min_rate : null;
        $cost = $realCost ?? $price;
        // Approximate: uses today's price snapshot and the lowest observed
        // purchase rate across the whole 6-month window, not what each
        // actually was on each individual sale date — flagged as
        // provisional until the manager confirms it's close enough, same as
        // the stock-status thresholds below.
        $estimatedProfit6mo = $cost !== null ? ($price - $cost) * $sold6mo : null;

        $topOrder = $topOrderBySku->get($sku);
        $topPharmacyOrder = $topPharmacyOrderBySku->get($sku);
        $stockoutCount = $stockoutCountBySku->get($sku, 0);

        $warehouseRows = $warehouseRowsBySku->get($sku, collect());
        $topWarehouse = $warehouseRows->sortByDesc('closing_stock')->first();
        $warehouseCount = $warehouseRows->count();

        $transferSuggestion = $this->suggestTransfer($warehouseRows, $warehouseVelocityBySku->get($sku, collect()), $price);

        return [
            'sku' => $sku,
            'item_name' => $item->name,
            'warehouse' => $item->warehouse,
            'top_warehouse_name' => $topWarehouse?->warehouse,
            'top_warehouse_stock' => $topWarehouse?->closing_stock,
            'warehouse_count' => $warehouseCount > 0 ? $warehouseCount : null,
            'transfer_suggested_from' => $transferSuggestion['from'],
            'transfer_suggested_to' => $transferSuggestion['to'],
            'transfer_suggested_qty' => $transferSuggestion['qty'],
            'estimated_weekly_loss_if_no_transfer' => $transferSuggestion['weekly_loss'],
            // No single "the vendor" per item — an item can be bought from
            // several vendors, which is exactly why there are three separate
            // vendor comparison columns below instead of one Vendor field.
            'current_stock' => $stock,
            'sold_6mo' => $sold6mo,
            'stockout_count_1yr' => $stockoutCount,
            'customer_count_6mo' => $customerCountBySku[$sku] ?? null,
            'top_customer_order_qty' => $topOrder->quantity ?? null,
            'top_customer_name' => $topOrder->customer_name ?? null,
            'top_pharmacy_order_qty' => $topPharmacyOrder->quantity ?? null,
            'top_pharmacy_name' => $topPharmacyOrder->customer_name ?? null,
            // Read back rather than recomputed here (see build()) — owned by
            // reports:import-expiry-report, just passed through so the
            // upsert re-writes it instead of erasing it and --push still
            // sends it.
            'expired_quantity' => $expiredQtyBySku[$sku] ?? null,
            'pending_po' => $pendingPo,
            'total_available' => $totalAvailable,
            'suggested_purchase' => $suggestedPurchase,
            'stock_status' => $status,
            'price' => $price,
            'cost' => $cost,
            'estimated_profit_6mo' => $estimatedProfit6mo,
            'most_purchased_vendor' => $mostPurchased?->vendor_name,
            'most_purchased_price' => $mostPurchased?->min_rate !== null ? (float) $mostPurchased->min_rate : null,
            'lowest_price_vendor' => $lowestPrice?->vendor_name,
            'lowest_price_value' => $lowestPrice?->min_rate !== null ? (float) $lowestPrice->min_rate : null,
            'best_discount_vendor' => $bestDiscount?->vendor_name,
            'best_discount_price' => $bestDiscount?->min_rate !== null ? (float) $bestDiscount->min_rate : null,
            'value' => $stock * $price,
        ];
    }

    /**
     * Provisional answer to the manager's "which items are overstocked in a
     * branch because of a transfer, and should we do a trial transfer
     * elsewhere" ask — inferred from his wording, not yet confirmed, so
     * flagged the same way as the stock-status thresholds above.
     *
     * Reuses the same per-branch days-of-coverage idea classify() uses
     * company-wide, just computed once per branch instead of once per SKU:
     * a branch running low (real demand there, but days-of-coverage under
     * LOW_DAYS) paired with a branch sitting on more than its own demand
     * needs (days-of-coverage over OVERSTOCK_DAYS, or literally zero demand
     * there) is read as "this stock would be better off moved". The
     * suggested quantity is capped by both sides — never more than the
     * shortfall actually needed, never more than the surplus actually
     * spare — and the loss estimate is a flat one-week extrapolation of the
     * short branch's own velocity at today's price, same order-of-magnitude
     * simplification as estimated_profit_6mo above.
     *
     * @param  Collection<int, InventoryWarehouseStock>  $stockRows
     * @param  Collection<int, object{warehouse: string, qty: int}>  $velocityRows
     * @return array{from: ?string, to: ?string, qty: ?int, weekly_loss: ?float}
     */
    private function suggestTransfer(Collection $stockRows, Collection $velocityRows, float $price): array
    {
        $none = ['from' => null, 'to' => null, 'qty' => null, 'weekly_loss' => null];

        $stockByWarehouse = $stockRows->pluck('closing_stock', 'warehouse');
        $soldByWarehouse = $velocityRows->pluck('qty', 'warehouse');
        $warehouses = $stockByWarehouse->keys()->merge($soldByWarehouse->keys())->unique();

        if ($warehouses->count() < 2) {
            return $none;
        }

        $branches = $warehouses->map(function (string $warehouse) use ($stockByWarehouse, $soldByWarehouse) {
            $stock = (int) ($stockByWarehouse[$warehouse] ?? 0);
            $sold = (int) ($soldByWarehouse[$warehouse] ?? 0);
            $velocity = $sold / self::VELOCITY_WINDOW_DAYS;

            return [
                'warehouse' => $warehouse,
                'stock' => $stock,
                'velocity' => $velocity,
                'days_of_coverage' => $velocity > 0 ? $stock / $velocity : ($stock > 0 ? PHP_FLOAT_MAX : 0.0),
            ];
        });

        $neediest = $branches
            ->filter(fn (array $b) => $b['velocity'] > 0 && $b['days_of_coverage'] < self::LOW_DAYS)
            ->sortBy('days_of_coverage')
            ->first();

        if ($neediest === null) {
            return $none;
        }

        $surplus = $branches
            ->filter(fn (array $b) => $b['warehouse'] !== $neediest['warehouse']
                && $b['stock'] > 0
                && ($b['velocity'] === 0.0 || $b['days_of_coverage'] > self::OVERSTOCK_DAYS))
            ->sortByDesc('days_of_coverage')
            ->first();

        if ($surplus === null) {
            return $none;
        }

        $deficit = max((int) ceil($neediest['velocity'] * self::TARGET_COVERAGE_DAYS) - $neediest['stock'], 0);
        $excess = max($surplus['stock'] - (int) ceil($surplus['velocity'] * self::TARGET_COVERAGE_DAYS), 0);
        $qty = min($deficit, $excess);

        if ($qty <= 0) {
            return $none;
        }

        return [
            'from' => $surplus['warehouse'],
            'to' => $neediest['warehouse'],
            'qty' => $qty,
            'weekly_loss' => round($neediest['velocity'] * $price * 7, 2),
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function classify(int $stock, int $sold6mo, int $totalAvailable, float $dailyVelocity): array
    {
        $target = (int) ceil($dailyVelocity * self::TARGET_COVERAGE_DAYS);

        if ($stock <= 0) {
            return ['OUT OF STOCK', max($target, 0)];
        }

        if ($sold6mo === 0) {
            return ['NO MOVEMENT', 0];
        }

        $daysOfCoverage = $dailyVelocity > 0 ? $totalAvailable / $dailyVelocity : PHP_FLOAT_MAX;

        if ($daysOfCoverage < self::CRITICAL_DAYS) {
            return ['CRITICAL', max($target - $totalAvailable, 0)];
        }

        if ($daysOfCoverage < self::LOW_DAYS) {
            return ['LOW', max($target - $totalAvailable, 0)];
        }

        if ($daysOfCoverage > self::OVERSTOCK_DAYS) {
            return ['OVERSTOCKED', 0];
        }

        return ['OK', 0];
    }
}
