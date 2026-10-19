<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Full-replacement rebuild of invoice_items from a corrected Zoho Analytics
 * Query Table export — built 2026-09-13 after discovering the two prior
 * invoice import paths (ImportItemSalesReturnHistoryReport's "Transaction
 * Number" and the original ImportInvoiceItemsAnalyticsReport join) produced
 * a document_number that did NOT match the real printed Invoice Number:
 * confirmed live by comparing a real invoice PDF (#84438) against the
 * stored rows for that document_number — 36 of 38 stored items didn't
 * belong to that invoice at all, while the invoice's real items (e.g.
 * AMLOCARD) were filed under other, nearby document numbers instead.
 *
 * Re-verified the join itself directly in Zoho Analytics as a plain Query
 * Table (not through our import code) — joining "Invoice Items" to
 * "Invoices" on "Invoice ID" with explicit `AS` aliases on every column —
 * and it reproduced invoice #84438's real line items exactly against the
 * PDF. So Zoho's data was never wrong; only our prior import path was. The
 * suspected cause is the earlier Query Table not using explicit `AS`
 * aliases, leaving Analytics to emit ambiguous auto-generated headers
 * (e.g. "i.Invoice Number") that this file's COLUMN_ALIASES matching
 * silently failed to recognize.
 *
 * Because the old data is corrupt rather than merely imprecise, this does
 * NOT do ImportInvoiceItemsAnalyticsReport's upgrade-in-place dance — it's
 * a straight insert. Pass --wipe on the FIRST file of the rebuild only, to
 * delete every existing invoice_items row from a prior zoho_export invoices
 * import before inserting the corrected data; omit it on subsequent
 * date-chunked files so they don't re-wipe each other's work.
 *
 * Expected source columns (all explicitly `AS`-aliased in the Query Table
 * SQL, so header text matches exactly, no "i."/"ii." prefixes):
 *   Product ID, Item Name, Quantity, Discount Amount (BCY), Total (BCY),
 *   Sub Total (BCY), Item Price, WH, City, Disc%, Invoice ID,
 *   Invoice Number, Invoice Date, and optionally Customer Name.
 */
class ImportInvoiceItemsFixedReport extends Command
{
    protected $signature = 'reports:import-invoice-items-fixed {path : Absolute path to the exported .xlsx file} {--wipe : Delete all existing zoho_export invoice_items rows before importing this file}';

    protected $description = 'Rebuild invoice_items from a corrected Zoho Analytics Invoice Items x Invoices query table export';

    private const COLUMN_ALIASES = [
        'document_number' => ['invoice number'],
        'product_id' => ['product id'],
        'item_name' => ['item name'],
        'quantity' => ['quantity'],
        'discount_amount' => ['discount amount (bcy)'],
        'total' => ['total (bcy)'],
        'sub_total' => ['sub total (bcy)'],
        'item_price' => ['item price'],
        'warehouse' => ['wh'],
        'city' => ['city'],
        'discount_percent' => ['disc%'],
        'invoice_date' => ['invoice date'],
        'invoice_id' => ['invoice id'],
        'customer_name' => ['customer name'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        if ($this->option('wipe')) {
            $oldImportIds = Import::where('type', 'invoices')->where('source', 'zoho_export')->pluck('id');
            $deleted = InvoiceItem::whereIn('import_id', $oldImportIds)->delete();
            $this->info("--wipe: deleted {$deleted} existing invoice_items rows from prior zoho_export invoice imports.");
        }

        ini_set('memory_limit', '512M');

        $skuByProductId = $this->loadSkuByProductId();
        $this->info('Loaded '.count($skuByProductId).' product_id => sku mappings from inventory_items.');

        $columns = null;
        $import = null;
        $rowsScanned = 0;
        $now = now();
        $imported = 0;
        $skippedNoSku = 0;
        $skippedNoDate = 0;

        foreach ($this->streamRows($path) as $row) {
            if ($columns === null) {
                $rowsScanned++;
                $candidate = $this->detectColumns($row);

                if (isset($candidate['product_id'])) {
                    $columns = $candidate;

                    foreach (['document_number', 'product_id', 'quantity', 'invoice_date'] as $required) {
                        if (! isset($columns[$required])) {
                            $this->error("Missing required column: {$required}. Columns seen: ".implode(', ', array_keys($columns)));

                            return self::FAILURE;
                        }
                    }

                    $import = Import::create([
                        'user_id' => User::first()->id,
                        'type' => 'invoices',
                        'source' => 'zoho_export',
                        'original_filename' => basename($path),
                        'stored_path' => null,
                        'source_signature' => 'zoho_export:invoices_fixed:'.uniqid(),
                        'status' => 'processing',
                        'row_count' => 0,
                    ]);
                } elseif ($rowsScanned >= 20) {
                    $this->error('Could not find a header row containing a "Product ID" column in this file.');

                    return self::FAILURE;
                }

                continue;
            }

            $documentNumber = trim((string) ($row[$columns['document_number']] ?? ''));
            $productIdRaw = $row[$columns['product_id']] ?? null;

            if ($documentNumber === '' || $productIdRaw === null || $productIdRaw === '') {
                continue;
            }

            $productId = (string) (int) round((float) $productIdRaw);
            $sku = $skuByProductId[$productId] ?? null;

            if ($sku === null) {
                $skippedNoSku++;

                continue;
            }

            $date = $this->toDate($row[$columns['invoice_date']] ?? null);
            if ($date === null) {
                $skippedNoDate++;

                continue;
            }

            $quantity = (int) round(abs($this->toFloat($row[$columns['quantity']] ?? null) ?? 0.0));

            $extra = [
                'item_name' => isset($columns['item_name']) ? (string) ($row[$columns['item_name']] ?? '') : null,
                'item_price' => isset($columns['item_price']) ? $this->toFloat($row[$columns['item_price']] ?? null) : null,
                'discount_amount' => isset($columns['discount_amount']) ? $this->toFloat($row[$columns['discount_amount']] ?? null) : null,
                'discount_percent' => isset($columns['discount_percent']) ? $this->toFloat($row[$columns['discount_percent']] ?? null) : null,
                'total' => isset($columns['total']) ? $this->toFloat($row[$columns['total']] ?? null) : null,
                'sub_total' => isset($columns['sub_total']) ? $this->toFloat($row[$columns['sub_total']] ?? null) : null,
                'city' => isset($columns['city']) ? (string) ($row[$columns['city']] ?? '') : null,
                'zoho_invoice_id' => isset($columns['invoice_id']) ? (string) ($row[$columns['invoice_id']] ?? '') : null,
                'source_file' => basename($path),
            ];

            $warehouse = isset($columns['warehouse']) ? trim((string) ($row[$columns['warehouse']] ?? '')) : null;
            $customerName = isset($columns['customer_name']) ? trim((string) ($row[$columns['customer_name']] ?? '')) : null;

            InvoiceItem::create([
                'import_id' => $import->id,
                'sku' => $sku,
                'document_number' => $documentNumber,
                'customer_name' => $customerName ?: null,
                'warehouse' => $warehouse ?: null,
                'quantity' => $quantity,
                'invoice_date' => $date,
                'status' => 'completed',
                'extra' => $extra,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $imported++;
        }

        if ($columns === null) {
            $this->error('Could not find a header row containing a "Product ID" column in this file.');

            return self::FAILURE;
        }

        $import->update(['status' => 'completed', 'row_count' => $imported]);

        $this->info("Done — {$imported} rows inserted, {$skippedNoSku} skipped (unknown product_id), {$skippedNoDate} skipped (unparsable date).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function loadSkuByProductId(): array
    {
        return InventoryItem::query()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.zoho_item_id')) as zoho_item_id, sku")
            ->whereNotNull('extra')
            ->get()
            ->filter(fn ($row) => $row->zoho_item_id !== null && $row->zoho_item_id !== '')
            ->pluck('sku', 'zoho_item_id')
            ->all();
    }

    /**
     * @return \Generator<int, array<int, string|null>>
     */
    private function streamRows(string $path): \Generator
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Could not open {$path} as a zip archive.");
        }

        $sharedStrings = $this->loadSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException("Could not read xl/worksheets/sheet1.xml from {$path}.");
        }

        $reader = new \XMLReader;
        $reader->XML($sheetXml);

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'row') {
                yield $this->parseRowXml($reader->readOuterXml(), $sharedStrings);
            }
        }

        $reader->close();
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, string|null>
     */
    private function parseRowXml(string $rowXml, array $sharedStrings): array
    {
        $row = [];
        $xml = simplexml_load_string($rowXml);

        foreach ($xml->c as $cell) {
            $ref = (string) $cell['r'];
            $colLetters = preg_replace('/\d+/', '', $ref);
            $colIndex = $this->columnLettersToIndex($colLetters);
            $type = (string) $cell['t'];

            $value = match (true) {
                $type === 's' => $sharedStrings[(int) $cell->v] ?? '',
                $type === 'inlineStr' => (string) ($cell->is->t ?? ''),
                isset($cell->v) => (string) $cell->v,
                default => null,
            };

            $row[$colIndex] = $value;
        }

        return $row;
    }

    /**
     * @return array<int, string>
     */
    private function loadSharedStrings(\ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlContent === false) {
            return [];
        }

        $reader = new \XMLReader;
        $reader->XML($xmlContent);

        $strings = [];
        $index = 0;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'si') {
                $si = simplexml_load_string($reader->readOuterXml());
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                    $text .= (string) $t;
                }
                $strings[$index] = $text;
                $index++;
            }
        }

        $reader->close();

        return $strings;
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1;
    }

    /**
     * @param  array<int, string|null>  $row
     * @return array<string, int>
     */
    private function detectColumns(array $row): array
    {
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

        return $columns;
    }

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

        if (is_numeric($value)) {
            $unixTimestamp = ((float) $value - 25569) * 86400;

            try {
                return Carbon::createFromTimestampUTC((int) round($unixTimestamp))->toDateString();
            } catch (\Throwable) {
                return null;
            }
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
