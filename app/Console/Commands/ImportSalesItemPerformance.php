<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports Sales_Item_Performance.csv — a Zoho Analytics Query Table export,
 * ~766k rows, one row per item per invoice/credit note. Streamed via
 * fgetcsv rather than loaded whole, same pattern as
 * ImportSalesItemMonthlyPerformance. Kept as raw line items (not
 * pre-aggregated) so invoice-level totals can be summed per
 * transaction_number at query time.
 *
 * Wholesale-replaced on every run — a snapshot export, not an incremental
 * sync. Rows are written to a throwaway staging table
 * (sales_item_performance_staging_*) and only swapped into the live table
 * via an atomic RENAME TABLE once the full import has succeeded — never
 * TRUNCATE-then-reinsert against the live table directly. This exists
 * because a live-server run of SyncSalesItemPerformanceJob got SIGKILLed by
 * the host mid-truncate-and-reinsert on 2026-09-23, leaving the live table
 * with ~176k of ~766k rows and no way to tell without manually counting.
 * With the staging+swap approach, a kill at any point (host resource limit,
 * timeout, crash) leaves the live table completely untouched — only the
 * unused staging table is lost, and can just be dropped and retried.
 *
 * importFromFile() is the reusable core (also used as a building block by
 * SyncSalesItemPerformanceJob, which pulls this same view live via the Zoho
 * Analytics Bulk API instead of a manually-dropped file, but chunks the
 * import across multiple job executions rather than calling this directly
 * — see that job for why) — kept static so it needs no Command instance.
 */
class ImportSalesItemPerformance extends Command
{
    protected $signature = 'reports:import-sales-item-performance {path : Absolute path to Sales_Item_Performance.csv}';

    protected $description = 'Import (or refresh) per-invoice item-level sales/profit detail from a Sales_Item_Performance.csv export';

    /**
     * Below this many successfully-parsed rows, refuse to swap the staging
     * table in — almost certainly a bad export (auth hiccup, empty view,
     * truncated response) rather than a real ~766k-row snapshot. See the
     * matching guard in SyncSalesItemPerformanceJob.
     */
    public const MIN_PLAUSIBLE_ROWS = 1000;

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        try {
            $imported = self::importFromFile($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done — {$imported} rows imported (previous snapshot replaced).");

        return self::SUCCESS;
    }

    public static function importFromFile(string $path): int
    {
        $handle = fopen($path, 'r');
        $staging = null;

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $columnIndex = array_flip(array_map(fn ($h) => strtoupper(trim($h)), $headerRow));

            self::validateHeader($columnIndex);

            $staging = self::createStagingTable();

            $now = now();
            $batch = [];
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $customerId = self::toBigIntString($row[$columnIndex['CUSTOMER ID']] ?? null);
                $transactionNumber = trim((string) ($row[$columnIndex['TRANSACTION NUMBER']] ?? ''));

                if ($customerId === null || $transactionNumber === '') {
                    continue;
                }

                $batch[] = [
                    'zoho_transaction_id' => self::toBigIntString(self::cell($row, $columnIndex, 'Transaction ID')),
                    'transaction_number' => $transactionNumber,
                    'transaction_type' => self::cell($row, $columnIndex, 'Transaction Type'),
                    'transaction_date' => self::toDate(self::cell($row, $columnIndex, 'Transaction Date')),
                    'zoho_customer_id' => $customerId,
                    'zoho_item_id' => self::cell($row, $columnIndex, 'Item ID'),
                    'item_name' => self::cell($row, $columnIndex, 'Item Name'),
                    'zoho_location_id' => self::cell($row, $columnIndex, 'Location ID'),
                    'qty' => self::toFloat(self::cell($row, $columnIndex, 'QTY')),
                    'batch' => self::cell($row, $columnIndex, 'BATCH'),
                    'expiry' => self::cell($row, $columnIndex, 'EXPIRY'),
                    'item_price' => self::toFloat(self::cell($row, $columnIndex, 'Item Price')),
                    'discount_amount' => self::toFloat(self::cell($row, $columnIndex, 'Discount Amount')),
                    'cogs' => self::toFloat(self::cell($row, $columnIndex, 'COGS')),
                    'item_total' => self::toFloat(self::cell($row, $columnIndex, 'ITEM TOTAL')),
                    'item_gross_profit' => self::toFloat(self::cell($row, $columnIndex, 'ITEM GROSS PROFIT')),
                    'discount_percent' => self::toFloat(self::cell($row, $columnIndex, 'DISCOUNT %')),
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
                throw new RuntimeException("Only {$imported} valid rows parsed from the export — refusing to replace the existing data with it.");
            }

            self::swapStagingIntoLive($staging);

            return $imported;
        } catch (\Throwable $e) {
            if ($staging !== null) {
                self::dropStagingTable($staging);
            }

            throw $e;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @throws RuntimeException if a required column is missing
     */
    public static function validateHeader(array $columnIndex): void
    {
        $required = ['Transaction Number', 'Customer ID'];
        foreach ($required as $field) {
            if (! isset($columnIndex[strtoupper($field)])) {
                throw new RuntimeException("Missing required column: {$field}");
            }
        }
    }

    /**
     * Creates an empty clone of sales_item_performance to import into.
     * Never touches the live table — see swapStagingIntoLive().
     */
    public static function createStagingTable(): string
    {
        $staging = 'sales_item_performance_staging_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        DB::statement("CREATE TABLE `{$staging}` LIKE sales_item_performance");

        return $staging;
    }

    /**
     * Atomically swaps the fully-populated staging table in for the live
     * one. RENAME TABLE with multiple pairs is a single atomic operation in
     * MySQL/MariaDB, so there's no window where sales_item_performance is
     * missing or empty — readers either see the old data or the new data,
     * never neither.
     */
    public static function swapStagingIntoLive(string $staging): void
    {
        $old = 'sales_item_performance_old_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        DB::statement("RENAME TABLE sales_item_performance TO `{$old}`, `{$staging}` TO sales_item_performance");
        DB::statement("DROP TABLE `{$old}`");
    }

    public static function dropStagingTable(string $staging): void
    {
        DB::statement("DROP TABLE IF EXISTS `{$staging}`");
    }

    public static function cell(array $row, array $columnIndex, string $field): ?string
    {
        $field = strtoupper($field);
        if (! isset($columnIndex[$field])) {
            return null;
        }
        $value = trim((string) ($row[$columnIndex[$field]] ?? ''));

        return $value === '' ? null : $value;
    }

    public static function toBigIntString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $string = trim((string) $value);
        if (is_numeric($string) && (str_contains($string, 'E') || str_contains($string, 'e'))) {
            return sprintf('%.0f', (float) $string);
        }

        return $string;
    }

    public static function toFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $cleaned = preg_replace('/[^0-9.\-]/', '', $value);

        return $cleaned === '' || $cleaned === '-' ? null : (float) $cleaned;
    }

    public static function toDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::createFromFormat('d-m-Y', $value)->toDateString();
        } catch (\Throwable) {
            try {
                return \Illuminate\Support\Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
