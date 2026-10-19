<?php

namespace App\Console\Commands;

use App\Jobs\SyncSalesItemPerformanceJob;
use App\Models\ZohoConnection;
use Illuminate\Console\Command;

/**
 * Kicks off SyncSalesItemPerformanceJob — pulls the "Sales Item
 * Performance" Zoho Analytics view live via the Bulk API instead of
 * waiting on a manual CSV export/upload. See that job for the
 * create-export/poll/download/import flow.
 */
class SyncSalesItemPerformance extends Command
{
    protected $signature = 'reports:sync-sales-item-performance';

    protected $description = 'Refresh sales_item_performance live from the Zoho Analytics "Sales Item Performance" view (replaces the manual CSV export/import)';

    public function handle(): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        SyncSalesItemPerformanceJob::dispatch($connection);

        $this->info('Queued. Runs in the background via the scheduled queue worker — this table is ~766k rows, so the export/download/import can take a few minutes. Check storage/logs/laravel.log for progress.');

        return self::SUCCESS;
    }
}
