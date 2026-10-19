<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports Sales_Item_Monthly_Performance.csv — a Zoho Analytics Query Table
 * export, ~612k rows. Plain CSV (fgetcsv) streamed row-by-row rather than
 * loaded whole — this project's established pattern for large exports (see
 * ImportInvoiceItemsFixedReport for the equivalent .xlsx case), except CSV
 * needs none of the ZipArchive/XMLReader machinery XLSX streaming requires.
 *
 * Wholesale-replaced on every run — a snapshot export, not an incremental
 * sync, and there's no natural per-row identity to upsert against. Rows are
 * written to a throwaway staging table and only swapped into the live table
 * via an atomic RENAME TABLE once the full import has succeeded — same
 * staging+swap pattern as ImportSalesItemPerformance (never a plain
 * TRUNCATE-then-reinsert against the live table), since this table is
 * comparable in size (~612k rows) to the one that got SIGKILLed mid-import
 * on 2026-09-23 — see [[project-sales-item-performance-sync]].
 *
 * importFromFile() is the reusable core; the manual command here just calls
 * it directly (no chunking needed for a manual, supervised run). The live
 * sync (SyncSalesItemMonthlyPerformanceJob) chunks the import across
 * multiple queue executions instead, same as SyncSalesItemPerformanceJob.
 */
class ImportSalesItemMonthlyPerformance extends Command
{
    protected $signature = 'reports:import-sales-item-monthly-performance {path : Absolute path to Sales_Item_Monthly_Performance.csv}';

    protected $description = 'Import (or refresh) monthly sales-by-item-by-customer from a Sales_Item_Monthly_Performance.csv export';

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
        } catch (\RuntimeException $e) {
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
            $columnIndex = array_flip(array_map('trim', $headerRow));

            self::validateHeader($columnIndex);

            $staging = self::createStagingTable();

            $now = now();
            $batch = [];
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $customerId = self::toBigIntString($row[$columnIndex['Customer ID']] ?? null);
                $year = $row[$columnIndex['Year']] ?? null;
                $month = $row[$columnIndex['Month']] ?? null;

                if ($customerId === null || $year === null || $year === '' || $month === null || $month === '') {
                    continue;
                }

                $batch[] = [
                    'year' => (int) $year,
                    'month' => (int) $month,
                    'zoho_customer_id' => $customerId,
                    'zoho_item_id' => self::cell($row, $columnIndex, 'Item ID'),
                    'item_name' => self::cell($row, $columnIndex, 'Item Name'),
                    'zoho_location_id' => self::cell($row, $columnIndex, 'Location ID'),
                    'qty' => self::toInt(self::cell($row, $columnIndex, 'QTY')),
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
                throw new \RuntimeException("Only {$imported} valid rows parsed from the export — refusing to replace the existing data with it.");
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
     * Recomputes this table directly from sales_item_performance (the
     * live-synced, complete transaction history) instead of pulling a
     * separate Zoho Analytics export — this table is just a monthly
     * aggregate of exactly the same data we already have. Pure local SQL,
     * no Zoho API call at all.
     *
     * Credit Note rows in sales_item_performance store item_total/
     * discount_amount/cogs/item_gross_profit as positive magnitudes (qty is
     * the only field already signed negative there) but must be subtracted,
     * not added, to get net figures — verified live 2026-09-23 against the
     * manually-uploaded sales_item_monthly_performance snapshot: computed
     * net item_total/discount_amount/cogs/item_gross_profit matched the
     * manual export to the cent (item_gross_profit off by 0.02 out of
     * ~67.6M, floating-point rounding). Getting the sign wrong here would
     * silently overstate every revenue figure in this module by ~18.3M
     * (the total Credit Note amount) — this is exactly the kind of formula
     * detail this module is riskier to get right than the earlier raw-Zoho
     * syncs (inventory/sales/invoices), which never needed this kind of
     * verification.
     */
    public static function recomputeFromSalesItemPerformance(): int
    {
        $staging = self::createStagingTable();

        try {
            DB::statement(<<<SQL
                INSERT INTO `{$staging}`
                    (year, month, zoho_customer_id, zoho_item_id, item_name, zoho_location_id,
                     qty, discount_amount, cogs, item_total, item_gross_profit, discount_percent,
                     created_at, updated_at)
                SELECT
                    YEAR(transaction_date) AS year,
                    MONTH(transaction_date) AS month,
                    zoho_customer_id,
                    zoho_item_id,
                    MAX(item_name) AS item_name,
                    zoho_location_id,
                    SUM(qty) AS qty,
                    SUM(CASE WHEN transaction_type = 'Credit Note' THEN -discount_amount ELSE discount_amount END) AS discount_amount,
                    SUM(CASE WHEN transaction_type = 'Credit Note' THEN -cogs ELSE cogs END) AS cogs,
                    SUM(CASE WHEN transaction_type = 'Credit Note' THEN -item_total ELSE item_total END) AS item_total,
                    SUM(CASE WHEN transaction_type = 'Credit Note' THEN -item_gross_profit ELSE item_gross_profit END) AS item_gross_profit,
                    (SUM(CASE WHEN transaction_type = 'Credit Note' THEN -discount_amount ELSE discount_amount END)
                        / NULLIF(SUM(CASE WHEN transaction_type = 'Credit Note' THEN -item_total ELSE item_total END)
                            + SUM(CASE WHEN transaction_type = 'Credit Note' THEN -discount_amount ELSE discount_amount END), 0)
                        * 100) AS discount_percent,
                    NOW(), NOW()
                FROM sales_item_performance
                WHERE transaction_date IS NOT NULL AND zoho_customer_id IS NOT NULL
                GROUP BY YEAR(transaction_date), MONTH(transaction_date), zoho_customer_id, zoho_item_id, zoho_location_id
            SQL);

            $imported = (int) DB::table($staging)->count();

            if ($imported < self::MIN_PLAUSIBLE_ROWS) {
                throw new \RuntimeException("Only {$imported} rows computed — refusing to replace the existing data with it.");
            }

            self::swapStagingIntoLive($staging);

            return $imported;
        } catch (\Throwable $e) {
            self::dropStagingTable($staging);

            throw $e;
        }
    }

    public static function validateHeader(array $columnIndex): void
    {
        $required = ['Year', 'Month', 'Customer ID'];
        foreach ($required as $field) {
            if (! isset($columnIndex[$field])) {
                throw new \RuntimeException("Missing required column: {$field}");
            }
        }
    }

    public static function createStagingTable(): string
    {
        $staging = 'sales_item_monthly_performance_staging_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        DB::statement("CREATE TABLE `{$staging}` LIKE sales_item_monthly_performance");

        return $staging;
    }

    public static function swapStagingIntoLive(string $staging): void
    {
        $old = 'sales_item_monthly_performance_old_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        DB::statement("RENAME TABLE sales_item_monthly_performance TO `{$old}`, `{$staging}` TO sales_item_monthly_performance");
        DB::statement("DROP TABLE `{$old}`");
    }

    public static function dropStagingTable(string $staging): void
    {
        DB::statement("DROP TABLE IF EXISTS `{$staging}`");
    }

    public static function cell(array $row, array $columnIndex, string $field): ?string
    {
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

    public static function toInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) preg_replace('/[^0-9.\-]/', '', $value));
    }
}
