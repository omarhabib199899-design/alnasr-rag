<?php

namespace App\Console\Commands;

use App\Models\CustomerDocumentInsight;
use App\Services\Inventory\ExcelImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports Customer_Document_Insights.xlsx — a Zoho Analytics Query Table
 * export listing missing/needed documents or data-quality actions per
 * customer (many rows per customer, unlike the other Elnasr Recommendations
 * source tables). Small file (~8,227 rows), plain ExcelImportService read.
 *
 * A standing snapshot, not an incremental sync — replaced wholesale on
 * every run (same pattern as SfdaPriceCheck) since there's no reliable
 * per-row identity to upsert against. Already safe against a
 * mid-run interruption without needing a staging-table swap:
 * DB::transaction() wraps a DELETE (not TRUNCATE, which auto-commits and
 * can't be rolled back) plus the reinsert, so a kill mid-run drops the DB
 * connection and MySQL automatically rolls the whole transaction back,
 * leaving the previous data untouched — same guarantee
 * ImportSalesItemPerformance gets from its staging+swap, different
 * mechanism.
 *
 * importFromCsvFile() is a second entry point (alongside the xlsx-reading
 * handle() below) used by SyncCustomerDocumentInsights, which pulls this
 * same view live via the Zoho Analytics Bulk API as CSV instead of a
 * manually-dropped xlsx file.
 */
class ImportCustomerDocumentInsights extends Command
{
    protected $signature = 'reports:import-customer-document-insights {path : Absolute path to Customer_Document_Insights.xlsx}';

    protected $description = "Import (or refresh) customer document/data-quality action items from a Customer_Document_Insights.xlsx export";

    private const COLUMNS = [
        'zoho_customer_id' => 'Customer ID',
        'customer_name' => 'Customer Name',
        'rep_name' => 'Rep Name',
        'action' => 'ACTION',
        'why' => 'WHY',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        ini_set('memory_limit', '512M');

        [$headers, $rows] = app(ExcelImportService::class)->read($path);

        $lookup = [];
        foreach ($headers as $index => $header) {
            $lookup[self::normalize($header)] = $index;
        }

        $columnIndex = [];
        foreach (self::COLUMNS as $field => $header) {
            $normalized = self::normalize($header);
            if (isset($lookup[$normalized])) {
                $columnIndex[$field] = $lookup[$normalized];
            }
        }

        if (! isset($columnIndex['zoho_customer_id']) || ! isset($columnIndex['action'])) {
            $this->error('Could not find "Customer ID" / "ACTION" columns. Headers seen: '.implode(', ', $headers));

            return self::FAILURE;
        }

        $now = now();
        $batch = [];
        $imported = 0;

        foreach ($rows as $row) {
            $customerId = self::toBigIntString($row[$columnIndex['zoho_customer_id']] ?? null);
            $action = trim((string) ($row[$columnIndex['action']] ?? ''));

            if ($customerId === null || $action === '') {
                continue;
            }

            $batch[] = [
                'zoho_customer_id' => $customerId,
                'customer_name' => isset($columnIndex['customer_name']) ? trim((string) ($row[$columnIndex['customer_name']] ?? '')) : '',
                'rep_name' => isset($columnIndex['rep_name']) ? (trim((string) ($row[$columnIndex['rep_name']] ?? '')) ?: null) : null,
                'action' => $action,
                'why' => isset($columnIndex['why']) ? (trim((string) ($row[$columnIndex['why']] ?? '')) ?: null) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $imported++;
        }

        DB::transaction(function () use ($batch) {
            CustomerDocumentInsight::query()->delete();
            foreach (array_chunk($batch, 500) as $chunk) {
                DB::table('customer_document_insights')->insert($chunk);
            }
        });

        $this->info("Done — {$imported} document insight rows imported (previous snapshot replaced).");

        return self::SUCCESS;
    }

    /**
     * CSV counterpart to handle()'s xlsx read, for the live Zoho Analytics
     * Bulk API export (requested as CSV) — same column mapping and
     * transactional-delete safety, different source format.
     */
    public static function importFromCsvFile(string $path): int
    {
        $handle = fopen($path, 'r');

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);

            $lookup = [];
            foreach ($headerRow as $index => $header) {
                $lookup[self::normalize($header)] = $index;
            }

            $columnIndex = [];
            foreach (self::COLUMNS as $field => $header) {
                $normalized = self::normalize($header);
                if (isset($lookup[$normalized])) {
                    $columnIndex[$field] = $lookup[$normalized];
                }
            }

            if (! isset($columnIndex['zoho_customer_id']) || ! isset($columnIndex['action'])) {
                throw new \RuntimeException('Could not find "Customer ID" / "ACTION" columns. Headers seen: '.implode(', ', $headerRow));
            }

            $now = now();
            $batch = [];
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $customerId = self::toBigIntString($row[$columnIndex['zoho_customer_id']] ?? null);
                $action = trim((string) ($row[$columnIndex['action']] ?? ''));

                if ($customerId === null || $action === '') {
                    continue;
                }

                $batch[] = [
                    'zoho_customer_id' => $customerId,
                    'customer_name' => isset($columnIndex['customer_name']) ? trim((string) ($row[$columnIndex['customer_name']] ?? '')) : '',
                    'rep_name' => isset($columnIndex['rep_name']) ? (trim((string) ($row[$columnIndex['rep_name']] ?? '')) ?: null) : null,
                    'action' => $action,
                    'why' => isset($columnIndex['why']) ? (trim((string) ($row[$columnIndex['why']] ?? '')) ?: null) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $imported++;
            }

            if ($imported < 100) {
                throw new \RuntimeException("Only {$imported} valid rows parsed from the export — refusing to replace the existing data with it.");
            }

            DB::transaction(function () use ($batch) {
                CustomerDocumentInsight::query()->delete();
                foreach (array_chunk($batch, 500) as $chunk) {
                    DB::table('customer_document_insights')->insert($chunk);
                }
            });

            return $imported;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private static function normalize(string $header): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $header)));
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
}
