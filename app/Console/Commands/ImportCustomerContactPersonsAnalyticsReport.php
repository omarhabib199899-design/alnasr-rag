<?php

namespace App\Console\Commands;

use App\Models\CustomerContactPerson;
use App\Services\Inventory\ExcelImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Imports the org's Zoho Analytics "Customer Contact Persons" view — 7
 * columns, ~400 rows, confirmed live 2026-09-22. Small enough for
 * ExcelImportService's whole-file load (unlike the 42k-row Invoices export,
 * which needed streamed XML — see ImportInvoiceDocumentsAnalyticsReport for
 * why).
 *
 * Customer ID / Contact Person ID come through as scientific-notation text
 * (e.g. "6.850000000003328E15") — same precision trap as the Invoices
 * export; toBigIntString() converts back to a plain digit string matching
 * customers.zoho_contact_id.
 *
 * Upserts per contact person (updateOrCreate), never truncates — safe
 * against a mid-import interruption on its own, same as
 * ImportCustomerCreditUsage.
 *
 * importFromCsvFile() is a second entry point (alongside the xlsx-reading
 * handle() below) used by SyncCustomerContactPersons, which pulls this same
 * view live via the Zoho Analytics Bulk API as CSV instead of a
 * manually-dropped xlsx file.
 */
class ImportCustomerContactPersonsAnalyticsReport extends Command
{
    protected $signature = 'reports:import-customer-contact-persons-analytics {path : Absolute path to the exported .xlsx file}';

    protected $description = "Import (or refresh) customer contact persons from a Zoho Analytics 'Customer Contact Persons' view export";

    private const COLUMNS = [
        'zoho_contact_person_id' => 'Contact Person ID',
        'zoho_customer_id' => 'Customer ID',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'zoho_last_modified_time' => 'Last Modified Time',
        'zoho_created_time' => 'Created Time',
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
            $lookup[$this->normalize($header)] = $index;
        }

        $columnIndex = [];
        foreach (self::COLUMNS as $field => $header) {
            $normalized = $this->normalize($header);
            if (isset($lookup[$normalized])) {
                $columnIndex[$field] = $lookup[$normalized];
            }
        }

        if (! isset($columnIndex['zoho_contact_person_id'])) {
            $this->error('Could not find a "Contact Person ID" column. Headers seen: '.implode(', ', $headers));

            return self::FAILURE;
        }

        $now = now();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $contactPersonId = $this->toBigIntString($row[$columnIndex['zoho_contact_person_id']] ?? null);

            if ($contactPersonId === null) {
                $skipped++;

                continue;
            }

            $record = [
                'zoho_contact_person_id' => $contactPersonId,
                'zoho_customer_id' => isset($columnIndex['zoho_customer_id']) ? $this->toBigIntString($row[$columnIndex['zoho_customer_id']] ?? null) : null,
                'first_name' => isset($columnIndex['first_name']) ? trim((string) ($row[$columnIndex['first_name']] ?? '')) ?: null : null,
                'last_name' => isset($columnIndex['last_name']) ? trim((string) ($row[$columnIndex['last_name']] ?? '')) ?: null : null,
                'email' => isset($columnIndex['email']) ? trim((string) ($row[$columnIndex['email']] ?? '')) ?: null : null,
                'zoho_created_time' => isset($columnIndex['zoho_created_time']) ? $this->toExcelDateTime($row[$columnIndex['zoho_created_time']] ?? null) : null,
                'zoho_last_modified_time' => isset($columnIndex['zoho_last_modified_time']) ? $this->toExcelDateTime($row[$columnIndex['zoho_last_modified_time']] ?? null) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            CustomerContactPerson::query()->updateOrCreate(['zoho_contact_person_id' => $contactPersonId], $record);
            $imported++;
        }

        $this->info("Done — {$imported} contact persons imported/updated, {$skipped} rows skipped (no Contact Person ID).");
        $this->info('Total contact persons now in table: '.CustomerContactPerson::count());

        return self::SUCCESS;
    }

    /**
     * CSV counterpart to handle()'s xlsx read, for the live Zoho Analytics
     * Bulk API export (requested as CSV). Dates come through as plain
     * strings here (not Excel serial numbers), so this parses with Carbon
     * directly instead of toExcelDateTime()'s serial-number path.
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

            if (! isset($columnIndex['zoho_contact_person_id'])) {
                throw new \RuntimeException('Could not find a "Contact Person ID" column. Headers seen: '.implode(', ', $headerRow));
            }

            $now = now();
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $contactPersonId = self::toBigIntString($row[$columnIndex['zoho_contact_person_id']] ?? null);

                if ($contactPersonId === null) {
                    continue;
                }

                $record = [
                    'zoho_contact_person_id' => $contactPersonId,
                    'zoho_customer_id' => isset($columnIndex['zoho_customer_id']) ? self::toBigIntString($row[$columnIndex['zoho_customer_id']] ?? null) : null,
                    'first_name' => isset($columnIndex['first_name']) ? trim((string) ($row[$columnIndex['first_name']] ?? '')) ?: null : null,
                    'last_name' => isset($columnIndex['last_name']) ? trim((string) ($row[$columnIndex['last_name']] ?? '')) ?: null : null,
                    'email' => isset($columnIndex['email']) ? trim((string) ($row[$columnIndex['email']] ?? '')) ?: null : null,
                    'zoho_created_time' => isset($columnIndex['zoho_created_time']) ? self::parseCsvDateTime($row[$columnIndex['zoho_created_time']] ?? null) : null,
                    'zoho_last_modified_time' => isset($columnIndex['zoho_last_modified_time']) ? self::parseCsvDateTime($row[$columnIndex['zoho_last_modified_time']] ?? null) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                CustomerContactPerson::query()->updateOrCreate(['zoho_contact_person_id' => $contactPersonId], $record);
                $imported++;
            }

            if ($imported < 50) {
                throw new \RuntimeException("Only {$imported} valid rows parsed from the export — suspiciously low, refusing to trust it.");
            }

            return $imported;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
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
}
