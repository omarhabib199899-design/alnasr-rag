<?php

namespace App\Jobs;

use App\Console\Commands\ImportCustomerCreditUsage;
use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps customer_credit_usage in sync with the "CREDIT LIMIT USAGE" Zoho
 * Analytics view (~1,472 rows, one per customer) via the Bulk API's async
 * export flow, instead of waiting on someone to manually re-export and
 * re-upload the CSV. Same create-export/poll/download flow as
 * SyncSalesItemPerformanceJob, but the import step itself doesn't need
 * chunking or a staging-table swap — ImportCustomerCreditUsage::
 * importFromFile() already upserts per customer (never truncates), so a
 * kill mid-import just leaves fewer rows updated, never an empty table.
 */
class SyncCustomerCreditUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    private const POLL_DELAY_SECONDS = 30;

    private const MAX_POLL_ATTEMPTS = 40;

    /**
     * Real export is ~1,472 rows; 100 is comfortably below any plausible
     * real result while still catching an accidentally-empty response.
     */
    private const MIN_PLAUSIBLE_ROWS = 100;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly ?string $exportJobId = null,
        public readonly int $pollAttempt = 0,
    ) {}

    public function handle(ZohoAnalyticsClient $client): void
    {
        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.customer_credit_usage');

        try {
            if ($this->exportJobId === null) {
                $jobId = $client->createExportJob($this->zohoConnection, $workspaceId, $viewId);
                self::dispatch($this->zohoConnection, $jobId, 0)
                    ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

                return;
            }

            $status = $client->getExportJobStatus($this->zohoConnection, $workspaceId, $this->exportJobId);

            if ($status['jobStatus'] !== 'JOB COMPLETED') {
                if ($this->pollAttempt >= self::MAX_POLL_ATTEMPTS) {
                    Log::error("SyncCustomerCreditUsageJob: export job {$this->exportJobId} still \"{$status['jobStatus']}\" after ".self::MAX_POLL_ATTEMPTS.' polls — giving up, existing data left untouched.');

                    return;
                }

                self::dispatch($this->zohoConnection, $this->exportJobId, $this->pollAttempt + 1)
                    ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

                return;
            }

            $csv = $client->downloadExportData($this->zohoConnection, $workspaceId, $this->exportJobId);
        } catch (Throwable $e) {
            Log::error('SyncCustomerCreditUsageJob failed: '.$e->getMessage());

            return;
        }

        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            Log::error("SyncCustomerCreditUsageJob: downloaded export only had {$lineCount} lines — refusing to import it.");

            return;
        }

        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/customer_credit_usage_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        try {
            $imported = ImportCustomerCreditUsage::importFromFile($tmpPath);
            Log::info("SyncCustomerCreditUsageJob: imported {$imported} rows from live Zoho Analytics export.");
        } catch (Throwable $e) {
            Log::error('SyncCustomerCreditUsageJob: import failed: '.$e->getMessage());
        } finally {
            @unlink($tmpPath);
        }
    }
}
