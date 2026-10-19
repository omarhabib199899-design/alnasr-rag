<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Merges Zoho Analytics's "SALES_ORDER_*_NDC" view export into sale_items —
 * a manager-granted Zoho Analytics workspace's year-chunked export of
 * item-level Sales Order history, discovered 2026-09-12 to close the
 * Sales Orders historical gap found during that day's data-completeness
 * audit (live API sync only reached ~3.6% of true history, missing April-
 * October 2024 entirely). Unlike ImportItemSalesReturnHistoryReport's
 * source view, this one carries a real per-row "Created Time" date, so
 * every row here gets an exact date rather than a period approximation.
 *
 * Reads the .xlsx directly as a zip + streamed XML (XMLReader) instead of
 * via PhpSpreadsheet's load()/toArray() — confirmed live (2026-09-12) that
 * even setReadDataOnly() + a 2048M ini_set() memory_limit still gets this
 * host's process silently SIGKilled on these 250k+ row files, because
 * PhpSpreadsheet builds a full in-memory Cell object graph for the whole
 * sheet before returning anything, and ini_set can't raise the host's own
 * hard per-process memory ceiling. Streaming one <row> at a time (plus a
 * shared-strings table that's only tens of thousands of entries, a few MB)
 * keeps peak memory roughly constant regardless of row count.
 */
class ImportSalesOrderAnalyticsReport extends Command
{
    protected $signature = 'reports:import-sales-order-analytics {path : Absolute path to the exported .xlsx file}';

    protected $description = "Merge a Zoho Analytics 'Sales Order' view export into sale_items";

    private const COLUMN_ALIASES = [
        'document_number' => ['sales order#', 'sales order #', 'sales order number'],
        'customer_number' => ['customer number'],
        'sku' => ['sku'],
        'item_price' => ['item price (bcy)', 'item price'],
        'discount_percent' => ['entity discount percent'],
        'quantity' => ['quantity'],
        'total' => ['total (bcy)', 'total'],
        'quantity_invoiced' => ['quantity invoiced'],
        'quantity_cancelled' => ['quantity cancelled'],
        'created_time' => ['created time'],
        'last_modified_time' => ['last modified time'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        ini_set('memory_limit', '512M');

        $columns = null;
        $import = null;
        $rowsScanned = 0;
        $imported = 0;
        $skippedExisting = 0;
        $skippedNoDate = 0;
        $now = now();

        // Single forward pass — a generator can't be rewound, and the
        // header row is always encountered first, so header-detection and
        // data-row processing share one loop instead of two.
        foreach ($this->streamRows($path) as $row) {
            if ($columns === null) {
                $rowsScanned++;
                $candidate = $this->detectColumns($row);

                if (isset($candidate['sku'])) {
                    $columns = $candidate;

                    foreach (['document_number', 'sku', 'quantity', 'created_time'] as $required) {
                        if (! isset($columns[$required])) {
                            $this->error("Missing required column: {$required}. Columns seen: ".implode(', ', array_keys($columns)));

                            return self::FAILURE;
                        }
                    }

                    $import = Import::create([
                        'user_id' => User::first()->id,
                        'type' => 'sales_orders',
                        'source' => 'zoho_export',
                        'original_filename' => basename($path),
                        'stored_path' => null,
                        'source_signature' => 'zoho_export:sales_orders:'.uniqid(),
                        'status' => 'processing',
                        'row_count' => 0,
                    ]);
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

            $date = $this->toDate($row[$columns['created_time']] ?? null);
            if ($date === null) {
                $skippedNoDate++;

                continue;
            }

            $exists = SaleItem::whereNull('zoho_document_id')
                ->where('document_number', $documentNumber)
                ->where('sku', $sku)
                ->exists();

            if ($exists) {
                $skippedExisting++;

                continue;
            }

            $ordered = $this->toFloat($row[$columns['quantity']] ?? null) ?? 0.0;
            $cancelled = isset($columns['quantity_cancelled']) ? ($this->toFloat($row[$columns['quantity_cancelled']] ?? null) ?? 0.0) : 0.0;
            $invoiced = isset($columns['quantity_invoiced']) ? ($this->toFloat($row[$columns['quantity_invoiced']] ?? null) ?? 0.0) : 0.0;
            $netOrdered = $ordered - $cancelled;
            $quantity = (int) round(max($netOrdered, 0));

            $status = match (true) {
                $netOrdered <= 0 => 'cancelled',
                $invoiced >= $netOrdered => 'completed',
                default => 'pending',
            };

            // "Customer Number" here is a Zoho code (e.g. "CUS-1321"), not a
            // display name — stored as-is since it's the only customer
            // identifier this view exposes.
            $customerNumber = isset($columns['customer_number']) ? trim((string) ($row[$columns['customer_number']] ?? '')) : null;

            SaleItem::create([
                'import_id' => $import->id,
                'sku' => $sku,
                'document_number' => $documentNumber,
                'customer_name' => $customerNumber ?: null,
                'quantity' => $quantity,
                'order_date' => $date,
                'status' => $status,
                'extra' => [
                    'item_price' => isset($columns['item_price']) ? $this->toFloat($row[$columns['item_price']] ?? null) : null,
                    'discount_percent' => isset($columns['discount_percent']) ? $this->toFloat($row[$columns['discount_percent']] ?? null) : null,
                    'total' => isset($columns['total']) ? $this->toFloat($row[$columns['total']] ?? null) : null,
                    'quantity_ordered' => (int) round($ordered),
                    'quantity_invoiced' => (int) round($invoiced),
                    'quantity_cancelled' => (int) round($cancelled),
                    'last_modified_time' => isset($columns['last_modified_time']) ? (string) ($row[$columns['last_modified_time']] ?? '') : null,
                    'source_file' => basename($path),
                ],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $imported++;
        }

        if ($columns === null) {
            $this->error('Could not find a header row containing an "SKU" column in this file.');

            return self::FAILURE;
        }

        $import->update(['status' => 'completed', 'row_count' => $imported]);

        $this->info("Done — {$imported} new rows inserted, {$skippedExisting} already covered — skipped, {$skippedNoDate} skipped for unparsable date.");

        return self::SUCCESS;
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

        // Confirmed live (2026-09-13): "Created Time" cells are typed as
        // Excel dates, so the raw XML <v> holds a numeric day-serial (with
        // a fractional time-of-day component, e.g. "45589.692094907405")
        // rather than a formatted string. Without this branch, passing
        // that raw numeric string to Carbon::parse() didn't throw (so
        // skippedNoDate stayed 0) but silently produced 1970-01-01 for
        // every single row on the first live run — this converts the
        // Excel serial to the real date instead.
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
