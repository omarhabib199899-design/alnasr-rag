<?php

namespace App\Jobs;

use App\Console\Commands\ImportSalesItemMonthlyPerformance;
use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps sales_item_monthly_performance in sync with the "Sales Item Monthly
 * Performance" Zoho Analytics view (~612k rows) via the Bulk API's async
 * export flow, instead of waiting on a manual CSV export/upload. Same
 * create-export/poll/download flow, chunked import, and staging-table
 * atomic-swap safety as SyncSalesItemPerformanceJob — see that job's
 * docblock and [[project-sales-item-performance-sync]] for the full
 * reasoning (host SIGKILL + Laravel's own per-job timeout, both fixed by
 * chunking + an explicit $timeout).
 */
class SyncSalesItemMonthlyPerformanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    private const POLL_DELAY_SECONDS = 30;

    private const MAX_POLL_ATTEMPTS = 40;

    private const MIN_PLAUSIBLE_ROWS = 1000;

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
        $viewId = (string) config('zoho.analytics.views.sales_item_monthly_performance');

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
                    Log::error("SyncSalesItemMonthlyPerformanceJob: export job {$this->exportJobId} still \"{$status['jobStatus']}\" after ".self::MAX_POLL_ATTEMPTS.' polls — giving up, existing data left untouched.');

                    return;
                }

                self::dispatch($this->zohoConnection, $this->exportJobId, $this->pollAttempt + 1)
                    ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

                return;
            }

            $csv = $client->downloadExportData($this->zohoConnection, $workspaceId, $this->exportJobId);
        } catch (Throwable $e) {
            Log::error('SyncSalesItemMonthlyPerformanceJob failed: '.$e->getMessage());

            return;
        }

        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            Log::error("SyncSalesItemMonthlyPerformanceJob: downloaded export only had {$lineCount} lines — refusing to replace the existing data with it.");

            return;
        }

        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/sales_item_monthly_performance_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        self::dispatch($this->zohoConnection, $this->exportJobId, $this->pollAttempt, null, $tmpPath, 0, 0)
            ->delay(now()->addSeconds(2));
    }

    private function processImportChunk(): void
    {
        $tmpPath = $this->tmpCsvPath;
        $staging = $this->stagingTable;

        if (! is_file($tmpPath)) {
            Log::error("SyncSalesItemMonthlyPerformanceJob: chunk import aborted, tmp file missing: {$tmpPath}");
            if ($staging !== null) {
                ImportSalesItemMonthlyPerformance::dropStagingTable($staging);
            }

            return;
        }

        $handle = fopen($tmpPath, 'r');

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $columnIndex = array_flip(array_map('trim', $headerRow));

            ImportSalesItemMonthlyPerformance::validateHeader($columnIndex);

            if ($staging === null) {
                $staging = ImportSalesItemMonthlyPerformance::createStagingTable();
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

                $customerId = ImportSalesItemMonthlyPerformance::toBigIntString($row[$columnIndex['Customer ID']] ?? null);
                $year = $row[$columnIndex['Year']] ?? null;
                $month = $row[$columnIndex['Month']] ?? null;

                if ($customerId !== null && $year !== null && $year !== '' && $month !== null && $month !== '') {
                    $batch[] = [
                        'year' => (int) $year,
                        'month' => (int) $month,
                        'zoho_customer_id' => $customerId,
                        'zoho_item_id' => ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'Item ID'),
                        'item_name' => ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'Item Name'),
                        'zoho_location_id' => ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'Location ID'),
                        'qty' => ImportSalesItemMonthlyPerformance::toInt(ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'QTY')),
                        'discount_amount' => ImportSalesItemMonthlyPerformance::toFloat(ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'Discount Amount')),
                        'cogs' => ImportSalesItemMonthlyPerformance::toFloat(ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'COGS')),
                        'item_total' => ImportSalesItemMonthlyPerformance::toFloat(ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'ITEM TOTAL')),
                        'item_gross_profit' => ImportSalesItemMonthlyPerformance::toFloat(ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'ITEM GROSS PROFIT')),
                        'discount_percent' => ImportSalesItemMonthlyPerformance::toFloat(ImportSalesItemMonthlyPerformance::cell($row, $columnIndex, 'DISCOUNT %')),
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
                if ($totalImported < ImportSalesItemMonthlyPerformance::MIN_PLAUSIBLE_ROWS) {
                    ImportSalesItemMonthlyPerformance::dropStagingTable($staging);
                    Log::error("SyncSalesItemMonthlyPerformanceJob: only {$totalImported} valid rows parsed from the export — refusing to replace the existing data with it.");
                } else {
                    ImportSalesItemMonthlyPerformance::swapStagingIntoLive($staging);
                    Log::info("SyncSalesItemMonthlyPerformanceJob: imported {$totalImported} rows from live Zoho Analytics export.");
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
                ImportSalesItemMonthlyPerformance::dropStagingTable($staging);
            }
            @unlink($tmpPath);
            Log::error('SyncSalesItemMonthlyPerformanceJob: chunk import failed, existing data left untouched: '.$e->getMessage());
        }
    }
}
