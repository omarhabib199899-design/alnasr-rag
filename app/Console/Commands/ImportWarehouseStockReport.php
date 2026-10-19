<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Merges one Zoho "Stock Summary Report" export (filtered to a single
 * Location in Zoho's UI before exporting) into inventory_warehouse_stock —
 * per-SKU stock at that one warehouse. Run once per warehouse, passing that
 * warehouse's name explicitly, since the export itself has no location
 * column (it's implicit from the Location filter used before exporting).
 *
 * Built as the manual-export alternative to an API-based per-item detail
 * fetch (~6,300 calls) that would compete with the invoices backfill's
 * shared daily Zoho quota — see PurchaseRecommendationBuilder's docblock
 * for the identical tradeoff on cost data.
 */
class ImportWarehouseStockReport extends Command
{
    protected $signature = 'reports:import-warehouse-stock {path : Absolute path to the exported .xlsx file} {warehouse : Warehouse/location name this export was filtered to, e.g. "JEDDAH"}';

    protected $description = "Merge one warehouse's Zoho Stock Summary Report export into inventory_warehouse_stock";

    private const COLUMN_ALIASES = [
        'sku' => ['sku'],
        'item_name' => ['item name'],
        'closing_stock' => ['closing stock'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        $warehouse = trim($this->argument('warehouse'));

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($path);
        $grid = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $located = $this->locateHeaderRow($grid);

        if ($located === null) {
            $this->error('Could not find a header row containing a "sku" column in this file.');

            return self::FAILURE;
        }

        [$headerRowIndex, $columns] = $located;

        if (! isset($columns['sku']) || ! isset($columns['closing_stock'])) {
            $this->error('Header row found, but missing "sku" or "closing stock". Columns seen: '.implode(', ', array_keys($columns)));

            return self::FAILURE;
        }

        $now = now();
        $rows = [];

        for ($i = $headerRowIndex + 1; $i < count($grid); $i++) {
            $row = $grid[$i];
            $sku = trim((string) ($row[$columns['sku']] ?? ''));

            if ($sku === '') {
                continue;
            }

            $closingStock = $this->toInt($row[$columns['closing_stock']] ?? null);
            $itemName = isset($columns['item_name']) ? trim((string) ($row[$columns['item_name']] ?? '')) : null;

            $rows[] = [
                'sku' => $sku,
                'warehouse' => $warehouse,
                'item_name' => $itemName ?: null,
                'closing_stock' => $closingStock,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            $this->warn('No data rows with a SKU found — nothing to import.');

            return self::SUCCESS;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inventory_warehouse_stock')->upsert(
                $chunk,
                ['sku', 'warehouse'],
                ['item_name', 'closing_stock', 'updated_at']
            );
        }

        $this->info(count($rows)." rows imported for warehouse \"{$warehouse}\".");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<int, mixed>>  $grid
     * @return array{0: int, 1: array<string, int>}|null
     */
    private function locateHeaderRow(array $grid): ?array
    {
        foreach ($grid as $rowIndex => $row) {
            $columns = [];
            foreach ($row as $colIndex => $cell) {
                $normalized = is_string($cell) ? strtolower(trim($cell)) : null;
                if ($normalized === null || $normalized === '') {
                    continue;
                }
                foreach (self::COLUMN_ALIASES as $field => $aliases) {
                    if (in_array($normalized, $aliases, true)) {
                        $columns[$field] = $colIndex;
                    }
                }
            }

            if (isset($columns['sku'])) {
                return [$rowIndex, $columns];
            }
        }

        return null;
    }

    /**
     * Clamped, not just cast — Zoho's own catalog has at least one test/junk
     * item ("TEST ITEM WITHOUT VAT") with a closing stock of
     * 9223372036854775807 (PHP_INT_MAX), which overflows the INT column and
     * fails the whole batch. No real warehouse holds anywhere near this
     * many units, so treat anything past a generous sanity bound as bad
     * data rather than a real stock count.
     */
    private const MAX_SANE_STOCK = 10_000_000;

    private function toInt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $cleaned = preg_replace('/[^0-9\-]/', '', (string) $value);

        if ($cleaned === '') {
            return 0;
        }

        $int = (int) $cleaned;

        return min(max($int, -self::MAX_SANE_STOCK), self::MAX_SANE_STOCK);
    }
}
