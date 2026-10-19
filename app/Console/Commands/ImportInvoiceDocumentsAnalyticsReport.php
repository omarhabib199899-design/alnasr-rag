<?php

namespace App\Console\Commands;

use App\Models\InvoiceDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use XMLReader;
use ZipArchive;

/**
 * Imports the org's Zoho Analytics "Invoices" view — document-level (not
 * line-item), ~42k rows, confirmed live 2026-09-22. See the
 * invoice_documents migration for why this table exists.
 *
 * Reads the .xlsx as a raw zip + streamed XML (same technique as
 * ImportInvoiceItemsFixedReport/ImportSalesOrderAnalyticsReport) rather than
 * via ExcelImportService/PhpSpreadsheet's load() — confirmed live
 * (2026-09-22) that PhpSpreadsheet's loader gets this host's process killed
 * on this file even with an IReadFilter limiting which rows get stored,
 * because it still has to parse the full document structure either way.
 *
 * Two precision traps in this specific export, both confirmed live:
 *
 * 1. Large Zoho ids (Invoice ID, Customer ID, PriceList ID, ...) come
 *    through as scientific-notation text (e.g. "6.850000000312571E15") —
 *    Excel's own raw cell text, not a parsing artifact. All these ids stay
 *    under 2^53 (~9.007e15) for this org, so a plain PHP float round-trips
 *    them exactly; toBigIntString() converts back to a plain digit string
 *    so `zoho_customer_id` matches `customers.zoho_contact_id` (which was
 *    imported as plain digit strings from a different export) byte-for-byte.
 * 2. Date/datetime columns are raw Excel serial numbers (e.g. "45382" for a
 *    date, "45391.360185185185" for a timestamp with a time-of-day
 *    fraction) — not formatted date strings. Converted via the same
 *    day-count-since-1899-12-30 formula ImportInvoiceItemsFixedReport uses.
 *
 * Upserts (InvoiceDocument::upsert), never truncates — safe against a
 * mid-import interruption on its own.
 *
 * importFromCsvFile() is a second entry point (alongside the xlsx-reading
 * handle() below) used by SyncInvoiceDocuments, which pulls this same view
 * live via the Zoho Analytics Bulk API as CSV instead of a manually-dropped
 * xlsx file — CSV cells are plain formatted dates, not Excel serial
 * numbers, so that path parses dates with Carbon directly instead of
 * toExcelDate()/toExcelDateTime().
 */
class ImportInvoiceDocumentsAnalyticsReport extends Command
{
    protected $signature = 'reports:import-invoice-documents-analytics {path : Absolute path to the exported .xlsx file}';

    protected $description = "Import (or refresh) invoice documents from a Zoho Analytics 'Invoices' view export";

    private const COLUMNS = [
        'zoho_invoice_id' => 'Invoice ID',
        'invoice_number' => 'Invoice Number',
        'invoice_date' => 'Invoice Date',
        'invoice_status' => 'Invoice Status',
        'zoho_customer_id' => 'Customer ID',
        'due_date' => 'Due Date',
        'discount_percent' => 'Discount (%)',
        'sub_total' => 'Sub Total (BCY)',
        'discount_amount' => 'Discount Amount',
        'adjustment' => 'Adjustment (BCY)',
        'total' => 'Total (BCY)',
        'salesperson_id' => 'Salesperson ID',
        'zoho_created_time' => 'Created Time',
        'quote_id' => 'Quote ID',
        'age_in_days' => 'Age In Days',
        'age_tier' => 'Age Tier',
        'subscription_id' => 'Subscription ID',
        'type' => 'Type',
        'balance' => 'Balance (BCY)',
        'purchase_order_number' => 'Purchase Order#',
        'exchange_rate' => 'Exchange Rate',
        'balance_fcy' => 'Balance (FCY)',
        'write_off_amount' => 'Write Off Amount (BCY)',
        'zoho_last_modified_time' => 'Last Modified Time',
        'pricelist_id' => 'PriceList ID',
        'discount_amount_bcy' => 'Discount Amount (BCY)',
        'discount_amount_fcy' => 'Discount Amount (FCY)',
        'transaction_type' => 'Transaction Type',
        'location_id' => 'Location ID',
        'status' => 'Status',
        'e_invoice_status' => 'e-Invoice Status.',
        'prepared_by' => 'PREPARED BY',
        'review_by' => 'REVIEW BY',
        'delivery_by' => 'DELIVERY BY',
        'invoice_attached_by' => 'INVOICE ATTACHED BY',
        'total_margin' => 'Total Margin',
        'exit_date_time' => 'EXIT DATE TIME',
        'delivery_date_time' => 'DELIVERY DATE TIME',
        'customer_check' => 'CUSTOMER CHECK',
        'no_of_errors_after_delivery' => 'NO OF ERRORS AFTER DELIVERY',
        'notes_1' => 'NOTES_1',
        'rasd' => 'RASD',
        'data_entry' => 'DATA ENTRY',
        'invoice_attachment_status' => 'INVOICE ATTACHMENT STATUS',
        'carton_no' => 'CARTON NO',
        'icebox_no' => 'ICEBOX NO',
        'pallets_no' => 'Pallets No',
        'final_status' => 'FINAL STATUS',
        'basic_payment' => 'BASIC PAYMENT',
    ];

    /** Fields that need big-int (scientific-notation-safe) string parsing. */
    private const ID_FIELDS = ['zoho_invoice_id', 'zoho_customer_id', 'pricelist_id', 'quote_id', 'subscription_id', 'location_id'];

    private const DATE_FIELDS = ['invoice_date', 'due_date'];

    private const DATETIME_FIELDS = ['zoho_created_time', 'zoho_last_modified_time', 'exit_date_time', 'delivery_date_time', 'basic_payment'];

    private const MONEY_FIELDS = ['sub_total', 'discount_amount', 'adjustment', 'total', 'balance', 'balance_fcy', 'write_off_amount', 'discount_amount_bcy', 'discount_amount_fcy', 'discount_percent', 'exchange_rate', 'total_margin'];

    private const INT_FIELDS = ['age_in_days', 'no_of_errors_after_delivery'];

    /** Fields that get their own typed column rather than living in `extra` — matches the invoice_documents migration. */
    private const DEDICATED_COLUMN_FIELDS = [
        'invoice_number' => true, 'zoho_customer_id' => true, 'invoice_date' => true, 'due_date' => true,
        'invoice_status' => true, 'status' => true, 'final_status' => true,
        'sub_total' => true, 'discount_amount' => true, 'adjustment' => true, 'total' => true,
        'balance' => true, 'balance_fcy' => true, 'write_off_amount' => true,
        'salesperson_id' => true, 'location_id' => true, 'purchase_order_number' => true,
        'age_in_days' => true, 'age_tier' => true, 'zoho_created_time' => true, 'zoho_last_modified_time' => true,
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        ini_set('memory_limit', '512M');

        $columnIndex = null;
        $now = now();
        $imported = 0;
        $skipped = 0;
        $batch = [];

        foreach ($this->streamRows($path) as $row) {
            if ($columnIndex === null) {
                $columnIndex = $this->matchColumns($row);

                if (! isset($columnIndex['zoho_invoice_id'])) {
                    $this->error('Could not find an "Invoice ID" column in this file.');

                    return self::FAILURE;
                }

                continue;
            }

            $invoiceId = $this->toBigIntString($row[$columnIndex['zoho_invoice_id']] ?? null);

            if ($invoiceId === null) {
                $skipped++;

                continue;
            }

            $record = ['zoho_invoice_id' => $invoiceId, 'created_at' => $now, 'updated_at' => $now];
            $extra = [];

            foreach (self::COLUMNS as $field => $header) {
                if ($field === 'zoho_invoice_id' || ! isset($columnIndex[$field])) {
                    continue;
                }

                $raw = $row[$columnIndex[$field]] ?? null;
                $value = match (true) {
                    in_array($field, self::ID_FIELDS, true) => $this->toBigIntString($raw),
                    in_array($field, self::DATE_FIELDS, true) => $this->toExcelDate($raw),
                    in_array($field, self::DATETIME_FIELDS, true) => $this->toExcelDateTime($raw),
                    in_array($field, self::MONEY_FIELDS, true) => $this->toFloat($raw),
                    in_array($field, self::INT_FIELDS, true) => $raw !== null && $raw !== '' ? (int) round((float) $raw) : null,
                    default => ($raw === null || $raw === '') ? null : trim((string) $raw),
                };

                if (array_key_exists($field, self::DEDICATED_COLUMN_FIELDS)) {
                    $record[$field] = $value;
                } else {
                    $extra[$field] = $value;
                }
            }

            $record['extra'] = json_encode($extra);
            $batch[] = $record;
            $imported++;

            if (count($batch) >= 500) {
                $this->upsertBatch($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $this->upsertBatch($batch);
        }

        if ($columnIndex === null) {
            $this->error('File appeared to have no rows.');

            return self::FAILURE;
        }

        $this->info("Done — {$imported} invoice documents imported/updated, {$skipped} rows skipped (no Invoice ID).");
        $this->info('Total invoice documents now in table: '.InvoiceDocument::count());

        return self::SUCCESS;
    }

    /**
     * CSV counterpart to handle()'s xlsx read, for the live Zoho Analytics
     * Bulk API export (requested as CSV). Same column mapping and upsert
     * safety, but dates come through as plain formatted strings here, not
     * Excel serial numbers — see class docblock.
     */
    public static function importFromCsvFile(string $path): int
    {
        $handle = fopen($path, 'r');

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $columnIndex = self::matchColumns($headerRow);

            if (! isset($columnIndex['zoho_invoice_id'])) {
                throw new \RuntimeException('Could not find an "Invoice ID" column in this export.');
            }

            $now = now();
            $imported = 0;
            $batch = [];

            while (($row = fgetcsv($handle)) !== false) {
                $invoiceId = self::toBigIntString($row[$columnIndex['zoho_invoice_id']] ?? null);

                if ($invoiceId === null) {
                    continue;
                }

                $record = ['zoho_invoice_id' => $invoiceId, 'created_at' => $now, 'updated_at' => $now];
                $extra = [];

                foreach (self::COLUMNS as $field => $header) {
                    if ($field === 'zoho_invoice_id' || ! isset($columnIndex[$field])) {
                        continue;
                    }

                    $raw = $row[$columnIndex[$field]] ?? null;
                    $value = match (true) {
                        in_array($field, self::ID_FIELDS, true) => self::toBigIntString($raw),
                        in_array($field, self::DATE_FIELDS, true) => self::parseCsvDate($raw),
                        in_array($field, self::DATETIME_FIELDS, true) => self::parseCsvDateTime($raw),
                        in_array($field, self::MONEY_FIELDS, true) => self::toFloat($raw),
                        in_array($field, self::INT_FIELDS, true) => $raw !== null && $raw !== '' ? (int) round((float) $raw) : null,
                        default => ($raw === null || $raw === '') ? null : trim((string) $raw),
                    };

                    if (array_key_exists($field, self::DEDICATED_COLUMN_FIELDS)) {
                        $record[$field] = $value;
                    } else {
                        $extra[$field] = $value;
                    }
                }

                $record['extra'] = json_encode($extra);
                $batch[] = $record;
                $imported++;

                if (count($batch) >= 500) {
                    self::upsertBatch($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                self::upsertBatch($batch);
            }

            if ($imported < 500) {
                throw new \RuntimeException("Only {$imported} valid rows parsed from the export — suspiciously low, refusing to trust it.");
            }

            return $imported;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private static function parseCsvDate(mixed $value): ?string
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

    private static function parseCsvDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Exactly the fields ZohoTransactionMapper::mapToInvoiceDocument() maps
     * from the live, hourly Zoho API sync (SyncZohoTransactionTypeJob's
     * "invoices_recent" cursor) — kept out of this nightly batch's UPDATE
     * clause so a slow-moving Zoho Analytics view (which syncs from Zoho
     * Books on ITS OWN schedule, confirmed lagging real operational changes
     * by up to a day) can never regress a row the live path already
     * corrected. upsert() still INSERTs these columns for a row the live
     * path hasn't reached yet — this only protects an EXISTING row from
     * being overwritten backwards on conflict.
     *
     * Root-caused live 2026-10-04 (Ahmed Mohsen, CUS-1184): this was the
     * actual mechanism behind "invoices stuck on draft despite being sent
     * in Zoho". The live hourly sync had already corrected one invoice's
     * status to 'sent'; the next 01:45 nightly run then clobbered it back
     * to 'draft' because Zoho's own Analytics view hadn't caught up to the
     * status change yet. Once that happens the hourly sync's watermark has
     * already passed that invoice's last_modified_time (nothing changed
     * again in Zoho), so nothing would ever have re-corrected it — a
     * permanent regression, not a few-hours lag. Excluding these columns
     * here removes the race entirely rather than just narrowing its window.
     */
    private const LIVE_SYNC_OWNED_FIELDS = [
        'invoice_number', 'zoho_customer_id', 'invoice_date', 'due_date', 'status',
        'sub_total', 'discount_amount', 'adjustment', 'total', 'balance',
        'write_off_amount', 'salesperson_id', 'location_id',
        'zoho_created_time', 'zoho_last_modified_time',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    private static function upsertBatch(array $batch): void
    {
        $columns = array_diff(array_keys($batch[0]), ['zoho_invoice_id', 'created_at'], self::LIVE_SYNC_OWNED_FIELDS);
        InvoiceDocument::query()->upsert($batch, ['zoho_invoice_id'], $columns);
    }

    /**
     * @param  array<int, string|null>  $headerRow
     * @return array<string, int>
     */
    private static function matchColumns(array $headerRow): array
    {
        $lookup = [];
        foreach ($headerRow as $index => $header) {
            if ($header === null) {
                continue;
            }
            $lookup[self::normalize((string) $header)] = $index;
        }

        $index = [];
        foreach (self::COLUMNS as $field => $header) {
            $normalized = self::normalize($header);
            if (isset($lookup[$normalized])) {
                $index[$field] = $lookup[$normalized];
            }
        }

        return $index;
    }

    private static function normalize(string $header): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', rtrim($header, '.'))));
    }

    /**
     * Converts a possibly-scientific-notation numeric string back to a
     * plain digit string, matching how the same Zoho id looks when it came
     * through a different export (e.g. customers.zoho_contact_id) as plain
     * text — see class docblock. Passes through anything that isn't
     * numeric-looking unchanged (ids are sometimes already plain text).
     */
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

    /**
     * Excel serial date (days since 1899-12-30) -> Y-m-d. Same formula as
     * ImportInvoiceItemsFixedReport::toDate().
     */
    private function toExcelDate(mixed $value): ?string
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

    private function toExcelDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $unixTimestamp = ((float) $value - 25569) * 86400;

            try {
                return Carbon::createFromTimestampUTC((int) round($unixTimestamp))->toDateTimeString();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $cleaned === '' || $cleaned === '-' ? null : (float) $cleaned;
    }

    /**
     * @return \Generator<int, array<int, string|null>>
     */
    private function streamRows(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Could not open {$path} as a zip archive.");
        }

        $sharedStrings = $this->loadSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException("Could not read xl/worksheets/sheet1.xml from {$path}.");
        }

        $reader = new XMLReader;
        $reader->XML($sheetXml);

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
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
    private function loadSharedStrings(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlContent === false) {
            return [];
        }

        $reader = new XMLReader;
        $reader->XML($xmlContent);

        $strings = [];
        $index = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
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
}
