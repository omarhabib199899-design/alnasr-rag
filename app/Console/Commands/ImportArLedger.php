<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use XMLReader;
use ZipArchive;

/**
 * Imports AR_Ledger_Master.xlsx (~66.7k rows) — a Zoho Analytics Query
 * Table export of every AR ledger transaction (invoices, credit notes,
 * payments, refunds, journals, opening balances).
 *
 * Streamed directly via ZipArchive + XMLReader rather than
 * ExcelImportService/PhpSpreadsheet, which loads the whole workbook into
 * memory and gets OOM-killed on this host for a file this size (confirmed
 * live: "Killed" with no error). Cell values are placed by their actual
 * column letter (from the cell's r="A2" reference), not by append order,
 * since XLSX rows may omit empty cells entirely.
 *
 * Wholesale-replaced on every run (truncate + reinsert) — a snapshot
 * export, not an incremental sync. NOTE: this xlsx-reading handle() still
 * uses a plain TRUNCATE (the same unsafe pattern that caused the
 * sales_item_performance incident, see [[project-sales-item-performance-sync]]
 * memory) — importFromCsvFile() below, used by SyncArLedger for the live
 * Zoho Analytics Bulk API pull, uses the safer staging-table+atomic-swap
 * pattern instead. The manual xlsx path is only run by hand under
 * supervision so the risk is lower, but it should eventually get the same
 * treatment if kept around.
 */
class ImportArLedger extends Command
{
    protected $signature = 'reports:import-ar-ledger {path : Absolute path to AR_Ledger_Master.xlsx}';

    protected $description = 'Import (or refresh) AR ledger transactions from an AR_Ledger_Master.xlsx export';

    /** Column order matches the source file's header row exactly. */
    private const COLUMNS = [
        'transaction_date', 'transaction_number', 'entity_type', 'category',
        'zoho_entity_id', 'zoho_transaction_id', 'zoho_customer_id', 'customer_number',
        'customer_name', 'ar_account_name', 'account_type', 'reference_no',
        'debit_or_credit', 'statement_amount', 'balance', 'inv_balance_due_date',
        'sales_exc_vat', 'cogs', 'gross_profit', 'gp_percent', 'kpi_amount', 'balance_aging',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $this->error('Could not open file as a zip/xlsx archive.');

            return self::FAILURE;
        }

        $sharedStrings = $this->readSharedStrings($zip);

        $sheetName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetName = $name;
                break;
            }
        }

        if ($sheetName === null) {
            $this->error('Could not find a worksheet inside the xlsx file.');
            $zip->close();

            return self::FAILURE;
        }

        $sheetXml = $zip->getFromName($sheetName);
        $zip->close();

        DB::table('ar_ledger_entries')->truncate();

        $reader = new XMLReader();
        $reader->XML($sheetXml);

        $currentRow = [];
        $rowNum = null;
        $inCell = false;
        $cellType = null;
        $cellValue = '';
        $cellCol = null;

        $now = now();
        $batch = [];
        $imported = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                $rowNum = (int) $reader->getAttribute('r');
                $currentRow = [];
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'c') {
                $cellType = $reader->getAttribute('t');
                $ref = $reader->getAttribute('r');
                $cellCol = $this->columnIndexFromRef($ref);
                $cellValue = '';
                $inCell = true;
            } elseif ($inCell && ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA)) {
                $cellValue .= $reader->value;
            } elseif ($inCell && $reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'c') {
                $val = $cellType === 's' ? ($sharedStrings[(int) $cellValue] ?? '') : $cellValue;
                if ($cellCol !== null) {
                    $currentRow[$cellCol] = $val;
                }
                $inCell = false;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') {
                if ($rowNum === 1) {
                    continue;
                }

                $customerId = $this->toBigIntString($currentRow[6] ?? null);
                if ($customerId === null) {
                    continue;
                }

                $record = ['zoho_customer_id' => $customerId, 'created_at' => $now, 'updated_at' => $now];
                foreach (self::COLUMNS as $index => $field) {
                    if ($field === 'zoho_customer_id') {
                        continue;
                    }
                    $raw = $currentRow[$index] ?? null;
                    $record[$field] = $this->castValue($field, $raw);
                }

                $batch[] = $record;
                $imported++;

                if (count($batch) >= 1000) {
                    DB::table('ar_ledger_entries')->insert($batch);
                    $batch = [];
                }
            }
        }
        $reader->close();

        if (! empty($batch)) {
            DB::table('ar_ledger_entries')->insert($batch);
        }

        $this->info("Done — {$imported} rows imported (previous snapshot replaced).");

        return self::SUCCESS;
    }

    /**
     * CSV counterpart to handle()'s xlsx read, for the live Zoho Analytics
     * Bulk API export (requested as CSV) — same fixed column order (row 1
     * header is skipped, not name-matched, same as the xlsx path), but
     * staged into a throwaway table and atomically swapped in only once
     * fully populated, rather than TRUNCATE-then-reinsert against the live
     * table. Dates come as plain strings here (not Excel serial numbers),
     * so this uses Carbon parsing instead of excelSerialToDate().
     */
    public static function importFromCsvFile(string $path): int
    {
        $handle = fopen($path, 'r');
        $staging = null;

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);

            $staging = 'ar_ledger_entries_staging_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
            DB::statement("CREATE TABLE `{$staging}` LIKE ar_ledger_entries");

            $now = now();
            $batch = [];
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $customerId = self::toBigIntString($row[6] ?? null);
                if ($customerId === null) {
                    continue;
                }

                $record = ['zoho_customer_id' => $customerId, 'created_at' => $now, 'updated_at' => $now];
                foreach (self::COLUMNS as $index => $field) {
                    if ($field === 'zoho_customer_id') {
                        continue;
                    }
                    $record[$field] = self::castCsvValue($field, $row[$index] ?? null);
                }

                $batch[] = $record;
                $imported++;

                if (count($batch) >= 1000) {
                    DB::table($staging)->insert($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                DB::table($staging)->insert($batch);
            }

            if ($imported < 1000) {
                DB::statement("DROP TABLE IF EXISTS `{$staging}`");

                throw new \RuntimeException("Only {$imported} valid rows parsed from the export — refusing to replace the existing data with it.");
            }

            $old = 'ar_ledger_entries_old_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
            DB::statement("RENAME TABLE ar_ledger_entries TO `{$old}`, `{$staging}` TO ar_ledger_entries");
            DB::statement("DROP TABLE `{$old}`");

            return $imported;
        } catch (\Throwable $e) {
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

    private static function castCsvValue(string $field, mixed $raw): mixed
    {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($field) {
            'transaction_date', 'inv_balance_due_date' => self::parseCsvDate($raw),
            'statement_amount', 'balance', 'sales_exc_vat', 'cogs', 'gross_profit', 'gp_percent', 'kpi_amount' => self::toFloat($raw),
            'zoho_entity_id', 'zoho_transaction_id' => self::toBigIntString($raw),
            default => (string) $raw,
        };
    }

    private static function parseCsvDate(string $value): ?string
    {
        try {
            return Carbon::createFromFormat('d/m/Y', $value)->toDateString();
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $strings = [];
        $reader = new XMLReader();
        $reader->XML($xml);
        $current = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                $current = '';
            } elseif ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                $current .= $reader->value;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'si') {
                $strings[] = $current;
            }
        }
        $reader->close();

        return $strings;
    }

    private function columnIndexFromRef(?string $ref): ?int
    {
        if ($ref === null || ! preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return null;
        }

        $letters = $m[1];
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1;
    }

    private function castValue(string $field, mixed $raw): mixed
    {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($field) {
            'transaction_date', 'inv_balance_due_date' => $this->excelSerialToDate($raw),
            'statement_amount', 'balance', 'sales_exc_vat', 'cogs', 'gross_profit', 'gp_percent', 'kpi_amount' => $this->toFloat($raw),
            'zoho_entity_id', 'zoho_transaction_id' => $this->toBigIntString($raw),
            default => (string) $raw,
        };
    }

    private function excelSerialToDate(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
}
