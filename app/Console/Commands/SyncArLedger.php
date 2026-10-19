<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the "AR Ledger Master" Zoho Analytics view live via the Bulk API
 * (as CSV) and imports it, instead of waiting on a manual XLSX export/
 * upload. Runs synchronously — see SyncCustomerCreditUsage for why (~66.7k
 * rows; smaller than the ~176k that got SIGKILLed on this host during the
 * sales_item_performance incident, and importFromCsvFile() uses the same
 * staging-table+atomic-swap safety regardless).
 */
class SyncArLedger extends Command
{
    protected $signature = 'reports:sync-ar-ledger';

    protected $description = 'Refresh ar_ledger_entries live from the Zoho Analytics "AR Ledger Master" view (replaces the manual XLSX export/import)';

    private const POLL_DELAY_SECONDS = 15;

    private const MAX_POLL_ATTEMPTS = 40;

    private const MIN_PLAUSIBLE_ROWS = 1000;

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.ar_ledger');

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
        $tmpPath = $tmpDir.'/ar_ledger_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        try {
            $imported = ImportArLedger::importFromCsvFile($tmpPath);
        } catch (Throwable $e) {
            $this->error('Import failed, existing data left untouched: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmpPath);
        }

        $this->info("Done — {$imported} AR ledger rows imported (previous snapshot replaced) from live Zoho Analytics export.");

        return self::SUCCESS;
    }
}
