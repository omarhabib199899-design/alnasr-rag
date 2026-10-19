<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls the "Collection_Classification_Staging" Zoho Analytics view live via
 * the Bulk API (same createExportJob/poll/download pattern as SyncArLedger)
 * into our own collection_classification_staging table, so
 * ComputeCollectionClassification can run the manager's exact ranking/median
 * SQL ourselves instead of depending on Zoho Analytics' own daily "Collection
 * Classification" Query Table output.
 *
 * This view carries the one thing ar_ledger_entries genuinely can't give us:
 * which specific payment settled which specific invoice, and when (Zoho's
 * own internal payment-allocation join — confirmed live 2026-10-06 that
 * ar_ledger_entries alone can't reconstruct this, only 7% of settled
 * invoices had a traceable invoice_payment match). We don't need to derive
 * Settlement Date/Closing Method ourselves — Zoho Analytics already computes
 * them; we just need our own live copy instead of waiting on its daily
 * batch, same reasoning as sync-ar-ledger.
 *
 * Column order confirmed live against the real export (2026-10-06): Customer
 * ID, Customer Number, Customer Name, Invoice ID, Invoice Number, Invoice
 * Date, Due Date, Invoice Amount (BCY), Invoice Balance (BCY), Invoice
 * Status, Write Off Amount (BCY), Total Applied Payments, Last Payment Date,
 * Total Applied Credit Notes, Last Credit Note Applied Date, Closing Method,
 * Settlement Date, Settlement Delay Days — only the 7 columns the
 * classification SQL actually reads are kept. Note Settlement Date/Last
 * Payment Date come as d/m/Y (slash) while Invoice Date/Due Date come as
 * d-m-Y (dash) — same export, two different date formats, confirmed live.
 *
 * All rows are kept (not pre-filtered to CLOSED BY PAYMENT) — the staging
 * table mirrors the Analytics source faithfully, same as every other
 * "_staging" table in config/zoho.php; ComputeCollectionClassification
 * applies the WHERE/ROW_NUMBER filtering itself, same split as Zoho
 * Analytics' own staging -> final table pattern.
 */
class SyncCollectionClassificationStaging extends Command
{
    protected $signature = 'reports:sync-collection-classification-staging';

    protected $description = 'Refresh collection_classification_staging live from the Zoho Analytics "Collection_Classification_Staging" view';

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
        $viewId = (string) config('zoho.analytics.views.collection_classification_staging');

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

        // Written to a real file and read back via fopen()/fgetcsv() (not
        // parsed from the in-memory string directly) so an embedded newline
        // inside a quoted field doesn't get mis-split — same reasoning and
        // same pattern as SyncArLedger -> ImportArLedger::importFromCsvFile().
        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/collection_classification_staging_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        try {
            $imported = self::importFromCsvFile($tmpPath);
        } catch (Throwable $e) {
            $this->error('Import failed, existing data left untouched: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmpPath);
        }

        $this->info("Done — {$imported} collection classification staging rows imported (previous snapshot replaced).");

        return self::SUCCESS;
    }

    /**
     * Staged into a throwaway table and atomically swapped in only once
     * fully populated — same safety pattern as ImportArLedger::importFromCsvFile(),
     * never a live TRUNCATE-then-reinsert.
     */
    public static function importFromCsvFile(string $path): int
    {
        $handle = fopen($path, 'r');
        $staging = null;

        try {
            $headerRow = fgetcsv($handle);
            if ($headerRow === false) {
                throw new \RuntimeException('Export had no header row.');
            }

            $staging = 'collection_classification_staging_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
            DB::statement("CREATE TABLE `{$staging}` LIKE collection_classification_staging");

            $now = now();
            $batch = [];
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $zohoCustomerId = ImportArLedger::toBigIntString($row[0] ?? null);
                $zohoInvoiceId = ImportArLedger::toBigIntString($row[3] ?? null);

                if ($zohoCustomerId === null || $zohoInvoiceId === null) {
                    continue;
                }

                $batch[] = [
                    'zoho_customer_id' => $zohoCustomerId,
                    'customer_number' => self::nullableString($row[1] ?? null),
                    'customer_name' => self::nullableString($row[2] ?? null),
                    'zoho_invoice_id' => $zohoInvoiceId,
                    'closing_method' => self::nullableString($row[15] ?? null),
                    'settlement_date' => self::parseSlashDate($row[16] ?? null),
                    'settlement_delay_days' => self::toInt($row[17] ?? null),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $imported++;

                if (count($batch) >= 1000) {
                    DB::table($staging)->insert($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                DB::table($staging)->insert($batch);
            }

            if ($imported < self::MIN_PLAUSIBLE_ROWS) {
                DB::statement("DROP TABLE IF EXISTS `{$staging}`");

                throw new \RuntimeException("Only {$imported} valid rows parsed from the export — refusing to replace the existing data with it.");
            }

            $old = 'collection_classification_staging_old_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
            DB::statement("RENAME TABLE collection_classification_staging TO `{$old}`, `{$staging}` TO collection_classification_staging");
            DB::statement("DROP TABLE `{$old}`");

            return $imported;
        } catch (Throwable $e) {
            if ($staging !== null) {
                DB::statement("DROP TABLE IF EXISTS `{$staging}`");
            }

            throw $e;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function parseSlashDate(mixed $value): ?string
    {
        $value = self::nullableString($value);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $value)->toDateString();
        } catch (Throwable) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (Throwable) {
                return null;
            }
        }
    }

    private static function toInt(mixed $value): ?int
    {
        $value = self::nullableString($value);

        return $value === null || ! is_numeric($value) ? null : (int) round((float) $value);
    }
}
