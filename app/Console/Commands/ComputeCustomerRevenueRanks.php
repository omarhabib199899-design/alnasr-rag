<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Computes each customer's Revenue Rank (by total item_total, all months in
 * sales_item_monthly_performance) and Sales Rank (by total quantity sold),
 * then writes them onto customer_sales_recommendations. Not present in any
 * of the manager's exports directly — Sales_Item_Monthly_Performance.csv is
 * the raw material these get computed from.
 *
 * Ranked in plain PHP rather than a SQL window function (RANK() OVER) to
 * avoid assuming a specific MySQL version supports it — this only runs
 * after a manual re-import, not on a hot path, so the extra simplicity is
 * worth more than the (still small, ~1,300-customer) query efficiency.
 */
class ComputeCustomerRevenueRanks extends Command
{
    protected $signature = 'reports:compute-customer-revenue-ranks';

    protected $description = 'Compute and store Revenue Rank / Sales Rank per customer on customer_sales_recommendations';

    public function handle(): int
    {
        $totals = DB::table('sales_item_monthly_performance')
            ->select('zoho_customer_id')
            ->selectRaw('SUM(item_total) as total_revenue')
            ->selectRaw('SUM(qty) as total_qty')
            ->groupBy('zoho_customer_id')
            ->get();

        $byRevenue = $totals->sortByDesc(fn ($row) => (float) $row->total_revenue)->values();
        $revenueRank = [];
        foreach ($byRevenue as $index => $row) {
            $revenueRank[$row->zoho_customer_id] = $index + 1;
        }

        $bySales = $totals->sortByDesc(fn ($row) => (float) $row->total_qty)->values();
        $salesRank = [];
        foreach ($bySales as $index => $row) {
            $salesRank[$row->zoho_customer_id] = $index + 1;
        }

        $now = now();
        $updated = 0;

        foreach ($totals as $row) {
            $affected = DB::table('customer_sales_recommendations')
                ->where('zoho_customer_id', $row->zoho_customer_id)
                ->update([
                    'total_revenue' => $row->total_revenue,
                    'revenue_rank' => $revenueRank[$row->zoho_customer_id] ?? null,
                    'sales_rank' => $salesRank[$row->zoho_customer_id] ?? null,
                    'updated_at' => $now,
                ]);
            $updated += $affected;
        }

        $this->info("Done — ranked {$totals->count()} customers by revenue/sales, updated {$updated} recommendation rows.");

        return self::SUCCESS;
    }
}
