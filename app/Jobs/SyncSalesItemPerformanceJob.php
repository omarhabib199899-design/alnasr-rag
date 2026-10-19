<?php

namespace App\Jobs;

use App\Console\Commands\ImportSalesItemPerformance;
use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps sales_item_performance in sync with the "Sales Item Performance"
 * Zoho Analytics view (766k+ rows) via the Bulk API's async export flow
 * (create job -> poll -> download), instead of waiting on someone to
 * manually re-export and re-upload the CSV. See
 * config/zoho.php ('analytics' key) for the workspace/view coordinates,
 * confirmed live 2026-09-23.
 *
 * Export + import of this size doesn't fit in one queue worker execution
 * (the export alone took well over 30s in testing), so this job
 * re-dispatches itself to poll rather than blocking with sleep() — same
 * self-redispatch shape as SyncZohoTransactionTypeJob, just polling a
 * Zoho-side async job instead of paging through a REST list.
 *
 * The import itself is *also* chunked across multiple self-redispatches
 * (CHUNK_ROWS rows per execution) rather than done in one shot: a live run
 * that tried to insert all ~766k rows in a single execution got SIGKILLed
 * by the host (exit 137, almost certainly a shared-hosting CPU/resource
 * quota) partway through on 2026-09-23. Each chunk is a fresh PHP process
 * with its own resource budget, and rows are written to a staging table via
 * ImportSalesItemPerformance::createStagingTable()/swapStagingIntoLive() —
 * so a kill at any point (mid-download, mid-chunk, whatever) leaves the
 * live table completely untouched. Only the very last chunk, once the
 * whole file has been read, does the atomic RENAME TABLE swap.
 */
class SyncSalesItemPerformanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Overrides queue:work's default 60s per-job timeout. A live run on
     * 2026-09-23 hit that default and got killed by Laravel's own
     * TimeoutExceededException mid-chunk (a different failure mode from the
     * host's SIGKILL this whole chunking scheme was built for) — 60s isn't
     * enough headroom for a CHUNK_ROWS-sized batch on this host under load.
     */
    public int $timeout = 180;

    /** 30s between polls, capped at 40 attempts (~20 minutes) before giving up. */
    private const POLL_DELAY_SECONDS = 30;

    private const MAX_POLL_ATTEMPTS = 40;

    /**
     * A completed export this small almost certainly means the API
     * returned something wrong (auth hiccup, empty table view, truncated
     * response) rather than a real snapshot — refuse to import/swap it in
     * over the existing 766k-row table on data this suspicious. The real
     * export is ~766,521 rows; 1,000 is comfortably below any plausible
     * real result while still catching an accidentally-empty one. This is
     * a cheap up-front check on the raw downloaded line count; the
     * authoritative check is ImportSalesItemPerformance::MIN_PLAUSIBLE_ROWS
     * on the actually-parsed row count once the chunked import finishes.
     */
    private const MIN_PLAUSIBLE_ROWS = 1000;

    /**
     * Rows imported per queue execution before self-redispatching to
     * continue. Keeps each execution's resource footprint small and bounded
     * instead of one long-running process — see class docblock for why.
     */
    private const CHUNK_ROWS = 20000;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly ?string $exportJobId = null,
        public readonly int $pollAttempt = 0,
        public readonly ?string $stagingTable = null,
        public readonly ?string $tmpCsvPath = null,
        public readonly int $fileOffset = 0,
        public readonly int $rowsImportedSoFar = 0,
    ) {}

    public function handle(ZohoAnalyticsClient $client): void
    {
        if ($this->tmpCsvPath !== null) {
            $this->processImportChunk();

            return;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.sales_item_performance');

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
                    Log::error("SyncSalesItemPerformanceJob: export job {$this->exportJobId} still \"{$status['jobStatus']}\" after ".self::MAX_POLL_ATTEMPTS.' polls — giving up, existing data left untouched.');

                    return;
                }

                self::dispatch($this->zohoConnection, $this->exportJobId, $this->pollAttempt + 1)
                    ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

                return;
            }

            $csv = $client->downloadExportData($this->zohoConnection, $workspaceId, $this->exportJobId);
        } catch (Throwable $e) {
            Log::error('SyncSalesItemPerformanceJob failed: '.$e->getMessage());

            return;
        }

        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            Log::error("SyncSalesItemPerformanceJob: downloaded export only had {$lineCount} lines — refusing to replace the existing data with it.");

            return;
        }

        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/sales_item_performance_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        // Hand off to a fresh execution for the import itself, so the
        // download's own resource usage doesn't eat into the first chunk's
        // budget in the same process.
        self::dispatch($this->zohoConnection, $this->exportJobId, $this->pollAttempt, null, $tmpPath, 0, 0)
            ->delay(now()->addSeconds(2));
    }

    private function processImportChunk(): void
    {
        $tmpPath = $this->tmpCsvPath;
        $staging = $this->stagingTable;

        if (! is_file($tmpPath)) {
            Log::error("SyncSalesItemPerformanceJob: chunk import aborted, tmp file missing: {$tmpPath}");
            if ($staging !== null) {
                ImportSalesItemPerformance::dropStagingTable($staging);
            }

            return;
        }

        $handle = fopen($tmpPath, 'r');

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $columnIndex = array_flip(array_map(fn ($h) => strtoupper(trim($h)), $headerRow));

            ImportSalesItemPerformance::validateHeader($columnIndex);

            if ($staging === null) {
                $staging = ImportSalesItemPerformance::createStagingTable();
            }

            if ($this->fileOffset > 0) {
                fseek($handle, $this->fileOffset);
            }

            $now = now();
            $batch = [];
            $chunkImported = 0;
            $rowsRead = 0;
            $eof = false;

            while ($rowsRead < self::CHUNK_ROWS) {
                $row = fgetcsv($handle);
                if ($row === false) {
                    $eof = true;
                    break;
                }
                $rowsRead++;

                $customerId = ImportSalesItemPerformance::toBigIntString($row[$columnIndex['CUSTOMER ID']] ?? null);
                $transactionNumber = trim((string) ($row[$columnIndex['TRANSACTION NUMBER']] ?? ''));

                if ($customerId !== null && $transactionNumber !== '') {
                    $batch[] = [
                        'zoho_transaction_id' => ImportSalesItemPerformance::toBigIntString(ImportSalesItemPerformance::cell($row, $columnIndex, 'Transaction ID')),
                        'transaction_number' => $transactionNumber,
                        'transaction_type' => ImportSalesItemPerformance::cell($row, $columnIndex, 'Transaction Type'),
                        'transaction_date' => ImportSalesItemPerformance::toDate(ImportSalesItemPerformance::cell($row, $columnIndex, 'Transaction Date')),
                        'zoho_customer_id' => $customerId,
                        'zoho_item_id' => ImportSalesItemPerformance::cell($row, $columnIndex, 'Item ID'),
                        'item_name' => ImportSalesItemPerformance::cell($row, $columnIndex, 'Item Name'),
                        'zoho_location_id' => ImportSalesItemPerformance::cell($row, $columnIndex, 'Location ID'),
                        'qty' => ImportSalesItemPerformance::toFloat(ImportSalesItemPerformance::cell($row, $columnIndex, 'QTY')),
                        'batch' => ImportSalesItemPerformance::cell($row, $columnIndex, 'BATCH'),
                        'expiry' => ImportSalesItemPerformance::cell($row, $columnIndex, 'EXPIRY'),
                        'item_price' => ImportSalesItemPerformance::toFloat(ImportSalesItemPerformance::cell($row, $columnIndex, 'Item Price')),
                        'discount_amount' => ImportSalesItemPerformance::toFloat(ImportSalesItemPerformance::cell($row, $columnIndex, 'Discount Amount')),
                        'cogs' => ImportSalesItemPerformance::toFloat(ImportSalesItemPerformance::cell($row, $columnIndex, 'COGS')),
                        'item_total' => ImportSalesItemPerformance::toFloat(ImportSalesItemPerformance::cell($row, $columnIndex, 'ITEM TOTAL')),
                        'item_gross_profit' => ImportSalesItemPerformance::toFloat(ImportSalesItemPerformance::cell($row, $columnIndex, 'ITEM GROSS PROFIT')),
                        'discount_percent' => ImportSalesItemPerformance::toFloat(ImportSalesItemPerformance::cell($row, $columnIndex, 'DISCOUNT %')),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $chunkImported++;

                    if (count($batch) >= 1000) {
                        DB::table($staging)->insert($batch);
                        $batch = [];
                    }
                }
            }

            if (! empty($batch)) {
                DB::table($staging)->insert($batch);
            }

            $newOffset = ftell($handle);
            fclose($handle);

            $totalImported = $this->rowsImportedSoFar + $chunkImported;

            if ($eof) {
                if ($totalImported < ImportSalesItemPerformance::MIN_PLAUSIBLE_ROWS) {
                    ImportSalesItemPerformance::dropStagingTable($staging);
                    Log::error("SyncSalesItemPerformanceJob: only {$totalImported} valid rows parsed from the export — refusing to replace the existing data with it.");
                } else {
                    ImportSalesItemPerformance::swapStagingIntoLive($staging);
                    Log::info("SyncSalesItemPerformanceJob: imported {$totalImported} rows from live Zoho Analytics export.");
                }

                @unlink($tmpPath);

                return;
            }

            self::dispatch($this->zohoConnection, $this->exportJobId, $this->pollAttempt, $staging, $tmpPath, $newOffset, $totalImported)
                ->delay(now()->addSeconds(2));
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($staging !== null) {
                ImportSalesItemPerformance::dropStagingTable($staging);
            }
            @unlink($tmpPath);
            Log::error('SyncSalesItemPerformanceJob: chunk import failed, existing data left untouched: '.$e->getMessage());
        }
    }
}
