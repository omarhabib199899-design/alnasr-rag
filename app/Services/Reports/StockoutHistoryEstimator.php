<?php

namespace App\Services\Reports;

use App\Models\InvoiceItem;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Estimates how many separate times each SKU's stock ran out over the past
 * year — one of the manager's requested insights (stockout frequency). No
 * daily stock snapshot has ever been recorded, so this can't be looked up;
 * it's reconstructed from today's known stock level plus every
 * stock-affecting transaction we already sync (sales reduce stock, returns
 * and received purchase orders restore it), walked backward in time.
 *
 * This is necessarily an approximation, not an audit-grade ledger:
 *  - A purchase order's transaction_date is when it was placed/recorded in
 *    Zoho, not necessarily the day goods physically arrived — "Zoho's exact
 *    purchase order status vocabulary for this org hasn't been empirically
 *    confirmed" (see PurchaseRecommendationBuilder's docblock), so which
 *    statuses count as "received stock" here is a best guess (open/closed/
 *    received), not verified against live data the way the rest of this
 *    sync was.
 *  - Stock adjustments, transfers between warehouses, and any event outside
 *    sales/returns/purchases aren't captured at all (not synced from Zoho).
 *  - The window is bounded by how far back the Zoho sync has actually
 *    reached, not a true calendar year, until the historical backfill
 *    finishes.
 *
 * Going forward, the honest fix is a real daily snapshot job so future
 * queries are exact instead of reconstructed — this covers the retroactive
 * gap in the meantime.
 */
class StockoutHistoryEstimator
{
    private const WINDOW_DAYS = 365;

    /**
     * @param  Collection<string, int>  $currentStockBySku
     * @return Collection<string, int> sku => estimated number of times stock hit zero or below in the window
     */
    public function estimate(Collection $currentStockBySku): Collection
    {
        $sinceDate = now()->subDays(self::WINDOW_DAYS)->toDateString();

        $events = collect()
            ->concat($this->invoiceEvents($sinceDate))
            ->concat($this->returnEvents($sinceDate))
            ->concat($this->purchaseReceivedEvents($sinceDate))
            ->groupBy('sku');

        return $currentStockBySku->map(function (int $currentStock, string $sku) use ($events) {
            $skuEvents = $events->get($sku);

            if ($skuEvents === null || $skuEvents->isEmpty()) {
                return 0;
            }

            return $this->countStockouts($currentStock, $skuEvents);
        });
    }

    /**
     * Sourced from invoice_items (Invoices), not sale_items (Sales Orders) —
     * same reasoning as PurchaseRecommendationBuilder's sold_6mo: stock
     * actually leaves the warehouse at invoice time, not when a sales order
     * is placed (which can sit in Draft or get cancelled without ever
     * moving stock), and invoice_items has a practical bulk-backfill path
     * (Zoho's "Stock Movement" report) that sale_items doesn't.
     *
     * @return Collection<int, array{sku: string, date: string, delta: int}>
     */
    private function invoiceEvents(string $sinceDate): Collection
    {
        return InvoiceItem::where('invoice_date', '>=', $sinceDate)
            ->whereNotIn('status', ['cancelled'])
            ->select('sku', 'invoice_date as date', 'quantity')
            ->get()
            ->map(fn ($row) => ['sku' => $row->sku, 'date' => (string) $row->date, 'delta' => -(int) $row->quantity]);
    }

    /**
     * @return Collection<int, array{sku: string, date: string, delta: int}>
     */
    private function returnEvents(string $sinceDate): Collection
    {
        return ReturnItem::where('transaction_date', '>=', $sinceDate)
            ->select('sku', 'transaction_date as date', 'quantity')
            ->get()
            ->map(fn ($row) => ['sku' => $row->sku, 'date' => (string) $row->date, 'delta' => (int) $row->quantity]);
    }

    /**
     * 'issued' deliberately excluded — confirmed live (2026-09-12) it means
     * the opposite of what this method needs: ImportPurchaseOrdersByItemReport
     * assigns 'issued' to a PO line that is NOT yet fully received (still
     * outstanding, the same meaning PurchaseRecommendationBuilder's
     * "pending PO" filter relies on). Counting it here as an arrival would
     * inflate the reconstructed stock level and systematically undercount
     * stockouts. 'closed' means fully received; 'received' is kept for
     * older Excel-imported rows that used that literal value.
     *
     * @return Collection<int, array{sku: string, date: string, delta: int}>
     */
    private function purchaseReceivedEvents(string $sinceDate): Collection
    {
        return PurchaseOrderItem::where('transaction_date', '>=', $sinceDate)
            ->whereIn('status', ['closed', 'received'])
            ->select('sku', 'transaction_date as date', 'quantity')
            ->get()
            ->map(fn ($row) => ['sku' => $row->sku, 'date' => (string) $row->date, 'delta' => (int) $row->quantity]);
    }

    /**
     * Walks events oldest-to-newest, replaying each delta on top of the
     * balance at the start of the window (today's stock minus every delta
     * in the window — the level implied at the window's start), and counts
     * each transition from a positive balance into zero-or-below as one
     * stockout — not every individual event while it stays at zero, which
     * would overcount one continuous shortage as many.
     *
     * @param  Collection<int, array{sku: string, date: string, delta: int}>  $events
     */
    private function countStockouts(int $currentStock, Collection $events): int
    {
        $sorted = $events->sortBy('date')->values();
        $netChange = $sorted->sum('delta');
        $balance = $currentStock - $netChange;

        $stockouts = 0;
        $wasPositive = $balance > 0;

        foreach ($sorted as $event) {
            $balance += $event['delta'];

            if ($wasPositive && $balance <= 0) {
                $stockouts++;
            }

            $wasPositive = $balance > 0;
        }

        return $stockouts;
    }
}
