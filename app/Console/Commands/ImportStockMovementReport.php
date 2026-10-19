<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\InvoiceItem;
use App\Models\ReturnItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Merges Zoho's "Stock Movement" report export (Inventory category, "Bills &
 * Invoices" stock-tracking mode) into invoice_items and return_items.
 *
 * This report is the only one found in this org that exposes per-line SKU
 * detail for sales at all — "Sales by Item" is aggregate-only (one row per
 * SKU per date range) and "Sales Order Details" is document-level (no SKU
 * column), so neither can backfill invoice_items/return_items the way
 * "Purchase Orders by Item" backfilled purchase_order_items. Confirmed live
 * (2026-09-12) it mixes four document types in one export via the
 * TRANSACTION column: Bill (Inward), Invoice (Outward), Credit Note
 * (Inward), and Inventory Adjustment By Quantity (Outward) — only Invoice
 * and Credit Note rows are handled here; Bill is skipped (purchase_order_items
 * already has a more accurate order-time source) and Inventory Adjustment
 * has no matching table.
 *
 * PurchaseRecommendationBuilder now reads invoice_items (not sale_items) for
 * sold_6mo/customer metrics specifically because this report exists — an
 * invoice is a confirmed, billed sale, unlike a sales order which can sit in
 * Draft or get cancelled without ever moving stock.
 */
class ImportStockMovementReport extends Command
{
    protected $signature = 'reports:import-stock-movement {path : Absolute path to the exported .xlsx/.xls file}';

    protected $description = "Merge Zoho's Stock Movement report export into invoice_items and return_items";

    private const COLUMN_ALIASES = [
        'date' => ['transaction date', 'date'],
        'document_number' => ['transaction number', 'reference number', 'document number'],
        'sku' => ['sku'],
        'transaction_type' => ['transaction type', 'transaction', 'reference type'],
        // Confirmed live (2026-09-12): this export's real header row has
        // "From Location Name" TWICE — the first occurrence is the actual
        // source warehouse, the second is mislabeled and actually holds the
        // contact/customer name instead (a Zoho export quirk, not ours).
        // Both occurrences are pulled out separately in locateHeaderRow()
        // (first -> warehouse, last -> contact_name) rather than through
        // this generic alias table, since a plain last-match-wins loop can't
        // assign two different field names to the same repeated header text.
        'contact_name' => ['contact name', 'customer name'],
        'warehouse' => ['warehouse', 'location name'],
        'quantity' => ['quantity'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        // This report's exports run 20-25k+ rows — PhpSpreadsheet's default
        // full-fidelity load (styles, formatting) blew past this host's
        // per-process memory cap and got silently SIGKILLed with zero
        // output. setReadDataOnly() skips all of that since this command
        // only ever reads cell values, and the raised memory_limit is a
        // second safety margin on top of it.
        ini_set('memory_limit', '1024M');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $grid = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        unset($spreadsheet);

        $located = $this->locateHeaderRow($grid);

        if ($located === null) {
            $this->error('Could not find a header row containing an "SKU" column in this file.');

            return self::FAILURE;
        }

        [$headerRowIndex, $columns] = $located;

        foreach (['date', 'document_number', 'sku', 'transaction_type', 'quantity'] as $required) {
            if (! isset($columns[$required])) {
                $this->error("Missing required column: {$required}. Columns seen: ".implode(', ', array_keys($columns)));

                return self::FAILURE;
            }
        }

        $userId = User::first()->id;
        $invoiceImport = null;
        $returnImport = null;

        $now = now();
        $invoicesImported = 0;
        $invoicesUpdated = 0;
        $returnsImported = 0;
        $returnsUpdated = 0;
        $skipped = 0;

        for ($i = $headerRowIndex + 1; $i < count($grid); $i++) {
            $row = $grid[$i];
            $sku = trim((string) ($row[$columns['sku']] ?? ''));
            $documentNumber = trim((string) ($row[$columns['document_number']] ?? ''));

            if ($sku === '' || $documentNumber === '') {
                continue;
            }

            $date = $this->toDate($row[$columns['date']] ?? null);
            if ($date === null) {
                continue;
            }

            // Confirmed live this column's real values are lowercase and
            // possibly compact ("invoice") rather than the report UI's
            // display label ("Invoice") — substring checks instead of exact
            // match also cover a compact "creditnote" if that's how Zoho
            // renders it here, which hasn't been directly observed.
            $transactionType = strtolower(trim((string) ($row[$columns['transaction_type']] ?? '')));
            $quantity = (int) round(abs($this->toFloat($row[$columns['quantity']] ?? null) ?? 0.0));
            $contactName = isset($columns['contact_name']) ? trim((string) ($row[$columns['contact_name']] ?? '')) : null;
            $warehouse = isset($columns['warehouse']) ? trim((string) ($row[$columns['warehouse']] ?? '')) : null;

            if (str_contains($transactionType, 'invoice')) {
                if ($invoiceImport === null) {
                    $invoiceImport = Import::create([
                        'user_id' => $userId,
                        'type' => 'invoices',
                        'source' => 'zoho_export',
                        'original_filename' => basename($path),
                        'stored_path' => null,
                        'source_signature' => 'zoho_export:invoices:'.uniqid(),
                        'status' => 'processing',
                        'row_count' => 0,
                    ]);
                }

                $existing = InvoiceItem::whereNull('zoho_document_id')
                    ->where('document_number', $documentNumber)
                    ->where('sku', $sku)
                    ->first();

                $attributes = [
                    'sku' => $sku,
                    'document_number' => $documentNumber,
                    'customer_name' => $contactName ?: null,
                    'warehouse' => $warehouse ?: null,
                    'quantity' => $quantity,
                    'invoice_date' => $date,
                    'status' => 'completed',
                    'updated_at' => $now,
                ];

                if ($existing) {
                    $existing->update($attributes);
                    $invoicesUpdated++;
                } else {
                    InvoiceItem::create($attributes + ['import_id' => $invoiceImport->id, 'created_at' => $now]);
                    $invoicesImported++;
                }
            } elseif (str_contains($transactionType, 'credit')) {
                if ($returnImport === null) {
                    $returnImport = Import::create([
                        'user_id' => $userId,
                        'type' => 'returns',
                        'source' => 'zoho_export',
                        'original_filename' => basename($path),
                        'stored_path' => null,
                        'source_signature' => 'zoho_export:returns:'.uniqid(),
                        'status' => 'processing',
                        'row_count' => 0,
                    ]);
                }

                $existing = ReturnItem::whereNull('zoho_document_id')
                    ->where('document_number', $documentNumber)
                    ->where('sku', $sku)
                    ->first();

                $attributes = [
                    'sku' => $sku,
                    'document_number' => $documentNumber,
                    'customer_name' => $contactName ?: null,
                    'quantity' => $quantity,
                    'transaction_date' => $date,
                    'status' => 'processed',
                    'updated_at' => $now,
                ];

                if ($existing) {
                    $existing->update($attributes);
                    $returnsUpdated++;
                } else {
                    ReturnItem::create($attributes + ['import_id' => $returnImport->id, 'created_at' => $now]);
                    $returnsImported++;
                }
            } else {
                // Bill (already covered by the more accurate Purchase Orders
                // by Item import) or Inventory Adjustment By Quantity (no
                // matching table) — not this command's job.
                $skipped++;
            }
        }

        $invoiceImport?->update(['status' => 'completed', 'row_count' => $invoicesImported]);
        $returnImport?->update(['status' => 'completed', 'row_count' => $returnsImported]);

        $this->info("Invoices: {$invoicesImported} new, {$invoicesUpdated} updated. Returns: {$returnsImported} new, {$returnsUpdated} updated. Skipped (Bill/Adjustment/other): {$skipped}.");

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
            $fromLocationIndices = [];

            foreach ($row as $colIndex => $cell) {
                $normalized = $this->normalizeHeader($cell);
                if ($normalized === null) {
                    continue;
                }

                if ($normalized === 'from location name') {
                    $fromLocationIndices[] = $colIndex;

                    continue;
                }

                foreach (self::COLUMN_ALIASES as $field => $aliases) {
                    if (in_array($normalized, $aliases, true)) {
                        $columns[$field] = $colIndex;
                    }
                }
            }

            // First "From Location Name" is the real source warehouse;
            // the duplicate second occurrence (when present) is where the
            // contact/customer name actually lives — see the class docblock.
            if (! empty($fromLocationIndices)) {
                $columns['warehouse'] = $fromLocationIndices[0];
                if (count($fromLocationIndices) > 1) {
                    $columns['contact_name'] = end($fromLocationIndices);
                }
            }

            if (isset($columns['sku'])) {
                return [$rowIndex, $columns];
            }
        }

        return null;
    }

    /**
     * Same rationale as ImportPurchaseOrdersByItemReport::normalizeHeader() —
     * this report's raw export header may use Zoho's internal snake_case
     * field names rather than the on-screen display labels.
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
