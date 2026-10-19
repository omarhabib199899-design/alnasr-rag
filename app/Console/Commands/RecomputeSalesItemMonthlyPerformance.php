<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Refreshes sales_item_monthly_performance by aggregating it directly from
 * sales_item_performance (already live-synced from Zoho) instead of pulling
 * a separate Zoho Analytics export — see
 * ImportSalesItemMonthlyPerformance::recomputeFromSalesItemPerformance()
 * for why this table doesn't need its own Zoho sync at all. Pure local SQL,
 * no Zoho API call, so this is fast and doesn't depend on network/Zoho
 * availability.
 *
 * Also re-runs reports:compute-customer-revenue-ranks afterward, since
 * Revenue Rank / Sales Rank on customer_sales_recommendations are computed
 * from this table and would otherwise go stale the moment this refreshes.
 */
class RecomputeSalesItemMonthlyPerformance extends Command
{
    protected $signature = 'reports:recompute-sales-item-monthly-performance';

    protected $description = 'Refresh sales_item_monthly_performance from sales_item_performance (local aggregation, no Zoho API call) and recompute customer revenue/sales ranks';

    public function handle(): int
    {
        try {
            $imported = ImportSalesItemMonthlyPerformance::recomputeFromSalesItemPerformance();
        } catch (Throwable $e) {
            $this->error('Recompute failed, existing data left untouched: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done — {$imported} rows computed (previous snapshot replaced).");

        $this->info('Recomputing customer revenue/sales ranks...');
        Artisan::call('reports:compute-customer-revenue-ranks', [], $this->output);

        return self::SUCCESS;
    }
}
