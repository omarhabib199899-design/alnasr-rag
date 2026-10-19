<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the "Final Sales Decision Engine" Zoho Analytics view live via the
 * Bulk API and imports it, instead of waiting on a manual CSV export/
 * upload. Runs synchronously — see SyncCustomerCreditUsage for why (same
 * small-table reasoning: ~1,346 customers).
 */
class SyncCustomerSalesRecommendations extends Command
{
    protected $signature = 'reports:sync-customer-sales-recommendations';

    protected $description = 'Refresh customer_sales_recommendations live from the Zoho Analytics "Final Sales Decision Engine" view (replaces the manual CSV export/import)';

    private const POLL_DELAY_SECONDS = 10;

    private const MAX_POLL_ATTEMPTS = 30;

    private const MIN_PLAUSIBLE_ROWS = 100;

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.customer_sales_recommendations');

        try {
            $this->info('Creating export job...');
            $jobId = $client->createExportJob($connection, $workspaceId, $viewId);

            $status = null;
            for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
                sleep(self::POLL_DELAY_SECONDS);
                $status = $client->getExportJobStatus($connection, $workspaceId, $jobId);
                $this->info("Poll {$attempt}: {$status['jobStatus']}");

                if ($status['jobStatus'] === 'JOB COMPLETED') {
                    break;
                }
            }

            if ($status === null || $status['jobStatus'] !== 'JOB COMPLETED') {
                $this->error('Export job never completed — existing data left untouched.');

                return self::FAILURE;
            }

            $csv = $client->downloadExportData($connection, $workspaceId, $jobId);
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            $this->error("Downloaded export only had {$lineCount} lines — refusing to import it, existing data left untouched.");

            return self::FAILURE;
        }

        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/customer_sales_recommendations_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        try {
            $imported = ImportCustomerSalesRecommendations::importFromFile($tmpPath);
        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmpPath);
        }

        $this->info("Done — {$imported} customer sales recommendations imported/updated from live Zoho Analytics export.");

        return self::SUCCESS;
    }
}
