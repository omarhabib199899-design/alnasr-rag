<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Merges Zoho's "Purchase Orders by Item" report export into
 * purchase_order_items — the manual-export shortcut for purchase order
 * history, same rationale as ImportZohoProfitReport/ImportWarehouseStockReport:
 * this report isn't capped by the account-wide Zoho API daily quota the way
 * the live sync's per-document detail fetch is, so it backfills years of PO
 * history in one export instead of the weeks the API-based sync would take.
 *
 * Rows from this import are distinguishable from live-synced ones by having
 * zoho_document_id/zoho_line_item_id NULL (same convention as the Excel
 * import path) — matched for re-import by (document_number, sku) instead,
 * since this report has no Zoho internal IDs to key on.
 *
 * The report has no status column, so it's inferred from quantity received
 * (falling back to quantity billed if that column isn't present) vs net
 * ordered quantity — not Zoho's literal status vocabulary, but mapped onto
 * the same 'issued'/'closed'/'cancelled' values live-synced rows use, so
 * PurchaseRecommendationBuilder's "pending PO" filter (status in
 * ['open', 'issued']) treats these rows consistently with API-synced ones.
 *
 * Confirmed live (2026-09-12) that this report's header row is Zoho's raw
 * internal field names (e.g. "purchaseorder_number", "quantity_received"),
 * not the display labels shown in the report UI — normalizeHeader() below
 * treats underscores/dots as spaces so both styles match the same alias.
 */
class ImportPurchaseOrdersByItemReport extends Command
{
    protected $signature = 'reports:import-purchase-orders-by-item {path : Absolute path to the exported .xlsx/.xls file}';

    protected $description = "Merge Zoho's Purchase Orders by Item report export into purchase_order_items";

    private const COLUMN_ALIASES = [
        'date' => ['date'],
        'po_number' => ['purchaseorder number', 'po number', 'p o #', 'po#'],
        'sku' => ['sku'],
        'vendor_name' => ['vendor name'],
        'quantity_ordered' => ['quantity ordered'],
        'quantity_cancelled' => ['quantity cancelled'],
        'quantity_billed' => ['quantity billed'],
        'quantity_received' => ['quantity received'],
        'amount' => ['amount'],
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

        $located = $this->locateHeaderRow($grid);

        if ($located === null) {
            $this->error('Could not find a header row containing an "SKU" column in this file.');

            return self::FAILURE;
        }

        [$headerRowIndex, $columns] = $located;

        foreach (['date', 'po_number', 'sku', 'quantity_ordered'] as $required) {
            if (! isset($columns[$required])) {
                $this->error("Missing required column: {$required}. Columns seen: ".implode(', ', array_keys($columns)));

                return self::FAILURE;
            }
        }

        $import = Import::create([
            'user_id' => User::first()->id,
            'type' => 'purchase_orders',
            'source' => 'zoho_export',
            'original_filename' => basename($path),
            'stored_path' => null,
            'source_signature' => 'zoho_export:purchase_orders:'.uniqid(),
            'status' => 'processing',
            'row_count' => 0,
        ]);

        $now = now();
        $imported = 0;
        $updated = 0;

        for ($i = $headerRowIndex + 1; $i < count($grid); $i++) {
            $row = $grid[$i];
            $sku = trim((string) ($row[$columns['sku']] ?? ''));
            $poNumber = trim((string) ($row[$columns['po_number']] ?? ''));

            if ($sku === '' || $poNumber === '') {
                continue;
            }

            $date = $this->toDate($row[$columns['date']] ?? null);
            if ($date === null) {
                continue;
            }

            $ordered = $this->toFloat($row[$columns['quantity_ordered']] ?? null) ?? 0.0;
            $cancelled = isset($columns['quantity_cancelled']) ? ($this->toFloat($row[$columns['quantity_cancelled']] ?? null) ?? 0.0) : 0.0;
            $received = isset($columns['quantity_received'])
                ? ($this->toFloat($row[$columns['quantity_received']] ?? null) ?? 0.0)
                : (isset($columns['quantity_billed']) ? ($this->toFloat($row[$columns['quantity_billed']] ?? null) ?? 0.0) : 0.0);
            $amount = isset($columns['amount']) ? $this->toFloat($row[$columns['amount']] ?? null) : null;
            $vendorName = isset($columns['vendor_name']) ? trim((string) ($row[$columns['vendor_name']] ?? '')) : null;

            $netOrdered = $ordered - $cancelled;
            $quantity = (int) round(max($netOrdered, 0));
            $rate = ($amount !== null && $ordered > 0) ? round($amount / $ordered, 2) : null;
            $status = match (true) {
                $netOrdered <= 0 => 'cancelled',
                $received >= $netOrdered => 'closed',
                default => 'issued',
            };

            $existing = PurchaseOrderItem::whereNull('zoho_document_id')
                ->where('document_number', $poNumber)
                ->where('sku', $sku)
                ->first();

            $attributes = [
                'sku' => $sku,
                'document_number' => $poNumber,
                'vendor_name' => $vendorName ?: null,
                'rate' => $rate,
                'quantity' => $quantity,
                'transaction_date' => $date,
                'status' => $status,
                'updated_at' => $now,
            ];

            if ($existing) {
                $existing->update($attributes);
                $updated++;
            } else {
                PurchaseOrderItem::create($attributes + ['import_id' => $import->id, 'created_at' => $now]);
                $imported++;
            }
        }

        $import->update(['status' => 'completed', 'row_count' => $imported]);

        $this->info("Done — {$imported} new rows inserted, {$updated} existing manually-imported rows updated.");

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
                $normalized = $this->normalizeHeader($cell);
                if ($normalized === null) {
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
     * This report's header row is Zoho's raw internal field names
     * ("purchaseorder_number", "quantity_received") rather than display
     * labels — collapsing underscores/dots to spaces lets the same alias
     * list match either style without duplicating every entry.
     */
    private function normalizeHeader(mixed $cell): ?string
    {
        if (! is_string($cell)) {
            return null;
        }

        $normalized = strtolower(trim(str_replace(['_', '.'], ' ', $cell)));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
