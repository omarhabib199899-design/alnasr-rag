<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Merges Zoho's own "Profit By Item" report export into purchase_recommendations
 * — real cost/profit/quantity numbers straight from Zoho's accounting, instead
 * of PurchaseRecommendationBuilder's PO-rate approximation (built because
 * Zoho's item.purchase_rate field is unreliable for this org — confirmed live,
 * it's a flat placeholder on ~4,300 of 6,287 items regardless of actual price).
 *
 * Only updates SKUs present in the export; every other SKU keeps whatever
 * PurchaseRecommendationBuilder last computed. Doesn't touch stock/status/
 * vendor columns — this is purely a profit/sales-volume correction.
 *
 * The export has a title row ("Profit By ItemFrom ... To ...") before the
 * real header row, unlike ExcelImportService's read() which assumes row 1 is
 * the header — hence the separate header-detection here instead of reusing it.
 */
class ImportZohoProfitReport extends Command
{
    protected $signature = 'reports:import-zoho-profit {path : Absolute path to the exported .xlsx file}';

    protected $description = "Merge Zoho's Profit By Item export into purchase_recommendations (real cost/profit, replacing the PO-rate approximation)";

    private const COLUMN_ALIASES = [
        'sku' => ['sku'],
        'quantity_sold' => ['quantity_sold', 'qty_sold', 'qty sold'],
        'total_cost' => ['total_cost', 'total cost'],
        'profit' => ['profit'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($path);
        $grid = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $columnIndex = $this->locateHeaderRow($grid);

        if ($columnIndex === null) {
            $this->error('Could not find a header row containing a "sku" column in this file.');

            return self::FAILURE;
        }

        [$headerRowIndex, $columns] = $columnIndex;

        if (! isset($columns['sku'])) {
            $this->error('Header row found, but no "sku" column in it. Columns seen: '.implode(', ', array_keys($columns)));

            return self::FAILURE;
        }

        $updates = [];
        for ($i = $headerRowIndex + 1; $i < count($grid); $i++) {
            $row = $grid[$i];
            $sku = trim((string) ($row[$columns['sku']] ?? ''));

            if ($sku === '') {
                continue;
            }

            $qty = isset($columns['quantity_sold']) ? $this->toFloat($row[$columns['quantity_sold']] ?? null) : null;
            $totalCost = isset($columns['total_cost']) ? $this->toFloat($row[$columns['total_cost']] ?? null) : null;
            $profit = isset($columns['profit']) ? $this->toFloat($row[$columns['profit']] ?? null) : null;

            $unitCost = ($totalCost !== null && $qty !== null && $qty > 0) ? round($totalCost / $qty, 2) : null;

            $updates[$sku] = [
                'cost' => $unitCost,
                'estimated_profit_6mo' => $profit,
                'sold_6mo' => $qty !== null ? (int) round($qty) : null,
            ];
        }

        if (empty($updates)) {
            $this->warn('No data rows with a SKU found — nothing to update.');

            return self::SUCCESS;
        }

        $this->info(count($updates).' SKUs found in the export. Updating...');

        $now = now();
        $matched = 0;
        $bar = $this->output->createProgressBar(count($updates));

        foreach ($updates as $sku => $fields) {
            $fields = array_filter($fields, fn ($v) => $v !== null);
            if (empty($fields)) {
                $bar->advance();

                continue;
            }

            $affected = DB::table('purchase_recommendations')
                ->where('sku', $sku)
                ->update($fields + ['updated_at' => $now]);

            $matched += $affected;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done — {$matched} of ".count($updates)." SKUs matched an existing recommendation row and were updated.");

        return self::SUCCESS;
    }

    /**
     * Scans rows for the first one whose cells include a "sku" header
     * (case-insensitive) — the export has an unpredictable number of title
     * rows before it, so this doesn't assume a fixed row index.
     *
     * @param  array<int, array<int, mixed>>  $grid
     * @return array{0: int, 1: array<string, int>}|null [header row index, column name => column index]
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

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $cleaned === '' ? null : (float) $cleaned;
    }
}
