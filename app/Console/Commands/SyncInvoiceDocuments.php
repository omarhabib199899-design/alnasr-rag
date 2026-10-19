<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the "INVOICE OPERATIONS NDC" Zoho Analytics view live via the Bulk
 * API and imports it, instead of waiting on a manual XLSX export/upload.
 * Runs synchronously — see SyncCustomerCreditUsage for why (~42k rows,
 * comfortably finishes in one execution, smaller than the ~176k that got
 * SIGKILLed on this host during the sales_item_performance incident).
 *
 * The view id here was matched by column-shape similarity (extensive
 * logistics/ops fields — CARTON NO, PREPARED BY, DELIVERY DATE TIME, etc.
 * matching invoice_documents' own dedicated columns) during an earlier
 * investigation, not a literal name confirmation from the source docblock —
 * medium-high confidence, not yet verified against a real live pull. If
 * this comes back with 0 useful columns matched (check the "Total invoice
 * documents now in table" count and MIN_PLAUSIBLE_ROWS guard below), the
 * view id in config/zoho.php needs re-checking directly in Zoho Analytics.
 */
class SyncInvoiceDocuments extends Command
{
    protected $signature = 'reports:sync-invoice-documents';

    protected $description = 'Refresh invoice_documents live from the Zoho Analytics "Invoices" / "INVOICE OPERATIONS NDC" view (replaces the manual XLSX export/import)';

    private const POLL_DELAY_SECONDS = 15;

    private const MAX_POLL_ATTEMPTS = 30;

    private const MIN_PLAUSIBLE_ROWS = 500;

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.invoice_documents');

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
        $tmpPath = $tmpDir.'/invoice_documents_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        try {
            $imported = ImportInvoiceDocumentsAnalyticsReport::importFromCsvFile($tmpPath);
        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmpPath);
        }

        $this->info("Done — {$imported} invoice documents imported/updated from live Zoho Analytics export.");

        return self::SUCCESS;
    }
}
