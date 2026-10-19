<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\InvoiceItem;
use App\Models\ReturnItem;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Merges Zoho Analytics's "Item Sales Return History" view export into
 * invoice_items and return_items — this view is a manager-granted Zoho
 * Analytics workspace's pre-built date-chunked export of item-level
 * Invoice/Credit Note history, discovered 2026-09-12 as a far larger source
 * than anything the Inventory API/report exports could reach (this org
 * exported it as 5 chunks: 2024 full year, then half-year chunks through
 * 2026 S2), specifically to close the ~20-month Invoices historical gap
 * found during the Zoho-Analytics data-completeness audit the same day.
 *
 * Unlike ImportStockMovementReport's source report, this view has NO
 * per-row transaction date column at all — only the file's own date range
 * (encoded in its filename, e.g. "..._2025_S1.xlsx" = Jan-Jun 2025) bounds
 * every row in it. Two consequences shape this command:
 *
 * 1. Every inserted row's date is the period's START date, not a real
 *    transaction date — an approximation, flagged via extra.date_precision
 *    so nothing downstream mistakes it for exact. This is only acceptable
 *    because PurchaseRecommendationBuilder's rolling windows are 6/12
 *    months from *today*, and these coarse dates land almost entirely in
 *    2024-2025, well outside both windows — the same reasoning already
 *    used to reassure the manager that the historical gaps didn't corrupt
 *    the live report (see the 2026-09-12 Zoho Analytics audit).
 * 2. Rows that already exist (matched by document_number+sku) are left
 *    untouched rather than updated — the March-September 2026 period this
 *    view also covers already has real per-day dates from
 *    ImportStockMovementReport, and overwriting those with this view's
 *    period-start approximation would make that data worse, not better.
 *
 * Reads the .xlsx directly as a zip + streamed XML (XMLReader) instead of
 * via PhpSpreadsheet's load()/toArray() — confirmed live (2026-09-12) that
 * even setReadDataOnly() + a 2048M ini_set() memory_limit still gets this
 * host's process silently SIGKilled on these 200k+ row files, because
 * PhpSpreadsheet builds a full in-memory Cell object graph for the whole
 * sheet before returning anything, and ini_set can't raise the host's own
 * hard per-process memory ceiling. Streaming one <row> at a time (plus a
 * shared-strings table that's only tens of thousands of entries, a few MB)
 * keeps peak memory roughly constant regardless of row count.
 */
class ImportItemSalesReturnHistoryReport extends Command
{
    protected $signature = 'reports:import-item-sales-return-history {path : Absolute path to the exported .xlsx file}';

    protected $description = "Merge a Zoho Analytics 'Item Sales Return History' view export into invoice_items and return_items";

    private const COLUMN_ALIASES = [
        'document_number' => ['transaction number'],
        'transaction_type' => ['transaction type'],
        'transaction_status' => ['transaction status'],
        'sku' => ['sku'],
        'warehouse' => ['wh'],
        'quantity' => ['qty'],
        'batch' => ['batch'],
        'expiry' => ['expiry'],
        'item_price' => ['item price'],
        'discount_amount' => ['discount amount'],
        'cogs' => ['cogs'],
        'item_total' => ['item total'],
        'item_gross_profit' => ['item gross profit'],
        'discount_percent' => ['discount %'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $periodStart = $this->derivePeriodStart(basename($path));
        if ($periodStart === null) {
            $this->error('Could not derive a period start date from the filename (expected "..._YYYY.xlsx" or "..._YYYY_S1/S2.xlsx"). Rename the file or extend derivePeriodStart().');

            return self::FAILURE;
        }

        $this->info("Using approximate transaction date: {$periodStart} (this view has no per-row date — see class docblock).");

        ini_set('memory_limit', '512M');

        $userId = User::first()->id;
        $columns = null;
        $rowsScanned = 0;
        $invoiceImport = null;
        $returnImport = null;

        $now = now();
        $invoicesImported = 0;
        $invoicesSkippedExisting = 0;
        $returnsImported = 0;
        $returnsSkippedExisting = 0;
        $skippedOther = 0;

        // Single forward pass — a generator can't be rewound, and the
        // header row is always encountered first, so header-detection and
        // data-row processing share one loop instead of two.
        foreach ($this->streamRows($path) as $row) {
            if ($columns === null) {
                $rowsScanned++;
                $candidate = $this->detectColumns($row);

                if (isset($candidate['sku'])) {
                    $columns = $candidate;

                    foreach (['document_number', 'transaction_type', 'sku', 'quantity'] as $required) {
                        if (! isset($columns[$required])) {
                            $this->error("Missing required column: {$required}. Columns seen: ".implode(', ', array_keys($columns)));

                            return self::FAILURE;
                        }
                    }
                } elseif ($rowsScanned >= 20) {
                    $this->error('Could not find a header row containing an "SKU" column in this file.');

                    return self::FAILURE;
                }

                continue;
            }

            $sku = trim((string) ($row[$columns['sku']] ?? ''));
            $documentNumber = trim((string) ($row[$columns['document_number']] ?? ''));

            if ($sku === '' || $documentNumber === '') {
                continue;
            }

            $transactionType = strtolower(trim((string) ($row[$columns['transaction_type']] ?? '')));
            $quantity = (int) round(abs($this->toFloat($row[$columns['quantity']] ?? null) ?? 0.0));
            $warehouse = isset($columns['warehouse']) ? trim((string) ($row[$columns['warehouse']] ?? '')) : null;

            $extra = [
                'transaction_status' => isset($columns['transaction_status']) ? (string) ($row[$columns['transaction_status']] ?? '') : null,
                'batch' => isset($columns['batch']) ? (string) ($row[$columns['batch']] ?? '') : null,
                'expiry' => isset($columns['expiry']) ? (string) ($row[$columns['expiry']] ?? '') : null,
                'item_price' => isset($columns['item_price']) ? $this->toFloat($row[$columns['item_price']] ?? null) : null,
                'discount_amount' => isset($columns['discount_amount']) ? $this->toFloat($row[$columns['discount_amount']] ?? null) : null,
                'cogs' => isset($columns['cogs']) ? $this->toFloat($row[$columns['cogs']] ?? null) : null,
                'item_total' => isset($columns['item_total']) ? $this->toFloat($row[$columns['item_total']] ?? null) : null,
                'item_gross_profit' => isset($columns['item_gross_profit']) ? $this->toFloat($row[$columns['item_gross_profit']] ?? null) : null,
                'discount_percent' => isset($columns['discount_percent']) ? $this->toFloat($row[$columns['discount_percent']] ?? null) : null,
                'date_precision' => 'period_approx',
                'source_file' => basename($path),
            ];

            if (str_contains($transactionType, 'invoice')) {
                $exists = InvoiceItem::whereNull('zoho_document_id')
                    ->where('document_number', $documentNumber)
                    ->where('sku', $sku)
                    ->exists();

                if ($exists) {
                    $invoicesSkippedExisting++;

                    continue;
                }

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

                InvoiceItem::create([
                    'import_id' => $invoiceImport->id,
                    'sku' => $sku,
                    'document_number' => $documentNumber,
                    'warehouse' => $warehouse ?: null,
                    'quantity' => $quantity,
                    'invoice_date' => $periodStart,
                    'status' => 'completed',
                    'extra' => $extra,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $invoicesImported++;
            } elseif (str_contains($transactionType, 'credit')) {
                $exists = ReturnItem::whereNull('zoho_document_id')
                    ->where('document_number', $documentNumber)
                    ->where('sku', $sku)
                    ->exists();

                if ($exists) {
                    $returnsSkippedExisting++;

                    continue;
                }

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

                // return_items has no warehouse column — kept in extra instead.
                $extra['warehouse'] = $warehouse ?: null;

                ReturnItem::create([
                    'import_id' => $returnImport->id,
                    'sku' => $sku,
                    'document_number' => $documentNumber,
                    'quantity' => $quantity,
                    'transaction_date' => $periodStart,
                    'status' => 'processed',
                    'extra' => $extra,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $returnsImported++;
            } else {
                $skippedOther++;
            }
        }

        if ($columns === null) {
            $this->error('Could not find a header row containing an "SKU" column in this file.');

            return self::FAILURE;
        }

        $invoiceImport?->update(['status' => 'completed', 'row_count' => $invoicesImported]);
        $returnImport?->update(['status' => 'completed', 'row_count' => $returnsImported]);

        $this->info("Invoices: {$invoicesImported} new (approx-dated {$periodStart}), {$invoicesSkippedExisting} already covered by more precise data — skipped. Returns: {$returnsImported} new, {$returnsSkippedExisting} already covered — skipped. Other/unrecognized type: {$skippedOther}.");

        return self::SUCCESS;
    }

    /**
     * Filenames observed live (2026-09-12): "..._2024.xlsx" (full year),
     * "..._2025_S1.xlsx" / "..._2025_S2.xlsx" (half-year chunks). S1 starts
     * the year, S2 starts its second half; a bare year starts Jan 1.
     */
    private function derivePeriodStart(string $filename): ?string
    {
        if (! preg_match('/(\d{4})(?:_S([12]))?\.xlsx$/i', $filename, $m)) {
            return null;
        }

        $year = $m[1];
        $half = $m[2] ?? null;
        $month = $half === '2' ? '07' : '01';

        return "{$year}-{$month}-01";
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

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $cleaned === '' ? null : (float) $cleaned;
    }
}
