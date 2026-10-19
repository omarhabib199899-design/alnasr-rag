<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Merges a Zoho Analytics custom Query Table export into invoice_items —
 * built 2026-09-12/13 to replace ImportItemSalesReturnHistoryReport's
 * period-approximated dates with real per-row invoice dates. The org's
 * "Invoice Items" table has no usable date of its own (Created Time/Last
 * Modified Time there are sync timestamps, not the invoice date — confirmed
 * live by finding a batch of rows all sharing today's exact timestamp) and
 * no readable invoice number, so the Query Table joins in both from the
 * "Invoices" header table:
 *
 *   SELECT item.*, inv."Invoice Date" AS "Invoice Date",
 *          inv."Invoice Number" AS "Invoice Number"
 *   FROM "Invoice Items" AS item
 *   LEFT JOIN "Invoices" AS inv ON item."Invoice ID" = inv."Invoice ID"
 *   WHERE inv."Invoice Date" BETWEEN '<period start>' AND '<period end>'
 *
 * exported in 8 date-chunked files (2024 full year, then quarters through
 * 2026 Q3) to stay under Zoho Analytics's 3,000,000-cell export limit.
 *
 * This table has no SKU column — only a "Product ID" (Zoho's internal
 * numeric item id) and an unrelated "Item ID" that does NOT match our SKUs
 * (confirmed live: scanning 2000 rows matched "Product ID" against known
 * inventory_items.extra.zoho_item_id values 2000/2000 times, "Item ID" 0
 * times). SKU is resolved via the same product_id => sku lookup
 * ZohoSyncService::skuByItemIdFromDatabase() builds for the live sync path.
 *
 * Because this source now has a real, precise date AND a real invoice
 * number (unlike the period-approximated import), a match against an
 * existing row is upgraded in place when that row is flagged
 * extra.date_precision === 'period_approx' — replacing the coarse guess
 * with the accurate date now that it's available — but left alone if it's
 * already precise (e.g. from ImportStockMovementReport), so this never
 * downgrades data that was already exact.
 *
 * Reads the .xlsx directly as a zip + streamed XML (XMLReader), same
 * technique as ImportSalesOrderAnalyticsReport/ImportItemSalesReturnHistoryReport
 * — these files run 100k+ rows and PhpSpreadsheet's load()/toArray() gets
 * this host's process silently SIGKilled on files that size.
 */
class ImportInvoiceItemsAnalyticsReport extends Command
{
    protected $signature = 'reports:import-invoice-items-analytics {path : Absolute path to the exported .xlsx file}';

    protected $description = "Merge a Zoho Analytics 'Invoice Items + Invoice Date/Number' query table export into invoice_items";

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
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        ini_set('memory_limit', '512M');

        $skuByProductId = $this->loadSkuByProductId();
        $this->info('Loaded '.count($skuByProductId).' product_id => sku mappings from inventory_items.');

        $columns = null;
        $import = null;
        $rowsScanned = 0;
        $now = now();
        $imported = 0;
        $upgraded = 0;
        $skippedExisting = 0;
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
                        'source_signature' => 'zoho_export:invoices:'.uniqid(),
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
                'source_file' => basename($path),
            ];

            $warehouse = isset($columns['warehouse']) ? trim((string) ($row[$columns['warehouse']] ?? '')) : null;

            $existing = InvoiceItem::whereNull('zoho_document_id')
                ->where('document_number', $documentNumber)
                ->where('sku', $sku)
                ->first();

            if ($existing) {
                $isApprox = ($existing->extra['date_precision'] ?? null) === 'period_approx';

                if (! $isApprox) {
                    $skippedExisting++;

                    continue;
                }

                $existing->update([
                    'warehouse' => $warehouse ?: $existing->warehouse,
                    'quantity' => $quantity,
                    'invoice_date' => $date,
                    'extra' => $extra,
                ]);
                $upgraded++;

                continue;
            }

            InvoiceItem::create([
                'import_id' => $import->id,
                'sku' => $sku,
                'document_number' => $documentNumber,
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

        $this->info("Done — {$imported} new rows inserted, {$upgraded} approx-dated rows upgraded to exact dates, {$skippedExisting} already-exact rows left alone, {$skippedNoSku} skipped (unknown product_id), {$skippedNoDate} skipped (unparsable date).");

        return self::SUCCESS;
    }

    /**
     * Same mapping ZohoSyncService::skuByItemIdFromDatabase() builds for the
     * live-sync path — reused here since this export's "Product ID" column
     * is confirmed (live, 2026-09-13) to be the same Zoho item id that
     * inventory_items.extra.zoho_item_id stores.
     *
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
     * Streams every <row> of the workbook's first sheet as [0-based column
     * index => cell value], including the header row — yielded in file
     * order via XMLReader so no more than one row (plus the shared-strings
     * lookup table) is ever held in memory at once.
     *
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

        // Confirmed live (2026-09-13): this column's cells are typed as
        // Excel dates, so the raw XML <v> holds a numeric day-serial (days
        // since 1899-12-30) rather than a formatted string — PhpSpreadsheet
        // applies the cell's display numFmt automatically, but this
        // streaming reader reads the raw value, so a purely numeric value
        // here needs the standard Excel-serial-to-Unix-timestamp formula
        // instead of a generic date-string parse (which failed 100% of the
        // time on the first live run before this fix).
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
