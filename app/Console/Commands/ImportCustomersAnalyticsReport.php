<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Inventory\ExcelImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-time (re-runnable) import of the org's Zoho Analytics "Customers"
 * view — same workspace already used for the invoice/sales historical
 * backfills (see ImportInvoiceItemsFixedReport), manually exported to Excel
 * since the live Zoho Contacts API isn't authorized yet (see
 * ZohoOAuthClient's SCOPE comment — adding it requires a reconnect, a
 * separate, later step). This is the customers table's first data source;
 * a live incremental Contacts API sync is a planned fast-follow.
 *
 * Column list and exact header text confirmed live 2026-09-22 against the
 * real export (82 columns, 1,470 rows) — not guessed. Uses
 * ExcelImportService (loads the whole file into memory) rather than the
 * streamed-XML approach some other Analytics importers use — safe here
 * since this file is a few thousand rows, not the 250k+ that forced
 * streaming for e.g. ImportSalesOrderAnalyticsReport.
 *
 * Upserts by zoho_contact_id so re-running after a fresh export just
 * updates existing rows (credit limit, AR balance, etc. all change over
 * time) rather than duplicating or requiring a wipe first.
 */
class ImportCustomersAnalyticsReport extends Command
{
    protected $signature = 'reports:import-customers-analytics {path : Absolute path to the exported .xlsx file}';

    protected $description = "Import (or refresh) customers from a Zoho Analytics 'Customers' view export";

    /**
     * field => exact export header text.
     */
    private const COLUMNS = [
        'zoho_contact_id' => 'Customer ID',
        'customer_name' => 'Customer Name',
        'display_name' => 'Display Name',
        'status' => 'Status',
        'zoho_created_time' => 'Created Time',
        'company_name' => 'Company Name',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'zoho_last_modified_time' => 'Last Modified Time',
        'credit_limit' => 'Credit Limit',
        'customer_number' => 'Customer Number',
        'cr_expiry_date' => 'CR Expiry Date',
        'attested_account' => 'Attested account',
        'last_reconciliation_date' => 'Last Reconciliation date',
        'rep_name' => 'Rep Name',
        'account_type' => 'Account Type',
        'city' => 'City',
        'accounts_receivable' => 'Accounts Receivable',
        'payments_due' => 'Payments Due',
        'management' => 'MANAGEMENT',
        'cr_status' => 'CR Status',
        'reconciliation_status' => 'RECONCILIATION STATUS',
        'pricelist_id' => 'PriceList ID',
        'portal_enabled' => 'Portal Enabled',
        'tax_registration_number' => 'Tax Registration Number',
        'cr_no' => 'CR NO',
        'gln_no' => 'GLN NO',
        'finance_dept_last_review_date' => 'Finance Dept Last Review Date',
        'promissory_note' => 'Promissory Note',
        'promissory_note_issue_date' => 'Promissory Note Issue Date',
        'moh_sfda_license_expiry_date' => 'MOH SFDA License Expiry Date',
        'delivery_time' => 'Delivery time',
        'finance_dept_recommendation' => 'Finance Dept Recommendation',
        'annual_sales_tgt' => 'Annual Sales TGT',
        'annual_collection_tgt' => 'Annual Collection TGT',
        'sales_owner' => 'SALES OWNER',
        'opening_balance' => 'Opening Balance',
        'warehouse_city' => 'WH CITY',
    ];

    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    private const BRANCH_COUNT = 10;

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        ini_set('memory_limit', '512M');

        [$headers, $rows] = app(ExcelImportService::class)->read($path);
        $columnIndex = $this->matchColumns($headers);

        if (! isset($columnIndex['zoho_contact_id']) || ! isset($columnIndex['customer_name'])) {
            $this->error('Could not find "Customer ID" / "Customer Name" columns. Headers seen: '.implode(', ', $headers));

            return self::FAILURE;
        }

        $now = now();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $zohoContactId = trim((string) ($row[$columnIndex['zoho_contact_id']] ?? ''));
            $customerName = trim((string) ($row[$columnIndex['customer_name']] ?? ''));

            if ($zohoContactId === '' || $customerName === '') {
                $skipped++;

                continue;
            }

            $record = [
                'zoho_contact_id' => $zohoContactId,
                'customer_name' => $customerName,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach (self::COLUMNS as $field => $header) {
                if ($field === 'zoho_contact_id' || $field === 'customer_name' || ! isset($columnIndex[$field])) {
                    continue;
                }

                $value = $row[$columnIndex[$field]] ?? null;
                $record[$field] = $this->castField($field, $value);
            }

            $record['extra'] = $this->buildExtra($row, $columnIndex);

            Customer::query()->updateOrCreate(['zoho_contact_id' => $zohoContactId], $record);
            $imported++;
        }

        $this->info("Done — {$imported} customers imported/updated, {$skipped} rows skipped (missing Customer ID or Name).");
        $this->info('Total customers now in table: '.Customer::count());

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    private function matchColumns(array $headers): array
    {
        $lookup = [];
        foreach ($headers as $index => $header) {
            $lookup[$this->normalize($header)] = $index;
        }

        $index = [];

        if (isset($lookup[$this->normalize('Customer ID')])) {
            $index['zoho_contact_id'] = $lookup[$this->normalize('Customer ID')];
        }
        if (isset($lookup[$this->normalize('Customer Name')])) {
            $index['customer_name'] = $lookup[$this->normalize('Customer Name')];
        }

        foreach (self::COLUMNS as $field => $header) {
            $normalized = $this->normalize($header);
            if (isset($lookup[$normalized])) {
                $index[$field] = $lookup[$normalized];
            }
        }

        // Branch location/city pairs and monthly targets go straight into
        // extra (buildExtra), not the main COLUMNS map — but need their own
        // indexes resolved here too.
        for ($i = 1; $i <= self::BRANCH_COUNT; $i++) {
            if (isset($lookup[$this->normalize("Location Branch {$i}")])) {
                $index["branch_{$i}_location"] = $lookup[$this->normalize("Location Branch {$i}")];
            }
            if (isset($lookup[$this->normalize("Branch {$i} City")])) {
                $index["branch_{$i}_city"] = $lookup[$this->normalize("Branch {$i} City")];
            }
        }

        foreach (self::MONTHS as $month) {
            if (isset($lookup[$this->normalize("{$month} SALES TGT")])) {
                $index["sales_tgt_{$month}"] = $lookup[$this->normalize("{$month} SALES TGT")];
            }
            if (isset($lookup[$this->normalize("{$month} COLLECTION TGT")])) {
                $index["collection_tgt_{$month}"] = $lookup[$this->normalize("{$month} COLLECTION TGT")];
            }
        }

        return $index;
    }

    private function normalize(string $header): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $header)));
    }

    private function castField(string $field, mixed $value): mixed
    {
        return match (true) {
            in_array($field, ['zoho_created_time', 'zoho_last_modified_time'], true) => $this->toDateTime($value),
            in_array($field, ['cr_expiry_date', 'last_reconciliation_date', 'finance_dept_last_review_date', 'promissory_note_issue_date', 'moh_sfda_license_expiry_date'], true) => $this->toDate($value),
            // credit_limit/opening_balance are genuinely "SAR X,XXX.XX"
            // formatted amounts in the export. accounts_receivable and
            // payments_due are NOT — confirmed live (2026-09-21): the
            // "Accounts Receivable" column actually holds a 16-digit
            // Zoho-internal reference id (e.g. "6850004007336975", the same
            // value across every customer — clearly not a per-customer
            // balance), which blew past decimal(14,2)'s range on the very
            // first import row. Both are kept as plain strings rather than
            // risk silently mangling a field whose real meaning isn't
            // pinned down yet.
            in_array($field, ['credit_limit', 'opening_balance'], true) => $this->toMoney($value),
            default => ($value === null || $value === '') ? null : trim((string) $value),
        };
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $columnIndex
     * @return array<string, mixed>
     */
    private function buildExtra(array $row, array $columnIndex): array
    {
        $extra = [];

        for ($i = 1; $i <= self::BRANCH_COUNT; $i++) {
            $location = isset($columnIndex["branch_{$i}_location"]) ? trim((string) ($row[$columnIndex["branch_{$i}_location"]] ?? '')) : '';
            $city = isset($columnIndex["branch_{$i}_city"]) ? trim((string) ($row[$columnIndex["branch_{$i}_city"]] ?? '')) : '';

            if ($location !== '' || $city !== '') {
                $extra['branches'][] = ['location' => $location ?: null, 'city' => $city ?: null];
            }
        }

        foreach (self::MONTHS as $month) {
            if (isset($columnIndex["sales_tgt_{$month}"])) {
                $extra['monthly_sales_targets'][$month] = $this->toMoney($row[$columnIndex["sales_tgt_{$month}"]] ?? null);
            }
            if (isset($columnIndex["collection_tgt_{$month}"])) {
                $extra['monthly_collection_targets'][$month] = $this->toMoney($row[$columnIndex["collection_tgt_{$month}"]] ?? null);
            }
        }

        if (isset($columnIndex['annual_sales_tgt'])) {
            $extra['annual_sales_tgt'] = $this->toMoney($row[$columnIndex['annual_sales_tgt']] ?? null);
        }
        if (isset($columnIndex['annual_collection_tgt'])) {
            $extra['annual_collection_tgt'] = $this->toMoney($row[$columnIndex['annual_collection_tgt']] ?? null);
        }

        foreach (['management' => 'MANAGEMENT', 'pricelist_id' => 'PriceList ID', 'delivery_time' => 'Delivery time', 'finance_dept_recommendation' => 'Finance Dept Recommendation', 'finance_dept_last_review_date' => 'Finance Dept Last Review Date', 'promissory_note' => 'Promissory Note', 'promissory_note_issue_date' => 'Promissory Note Issue Date'] as $field => $header) {
            if (isset($columnIndex[$field])) {
                $extra[$field] = trim((string) ($row[$columnIndex[$field]] ?? '')) ?: null;
            }
        }

        return $extra;
    }

    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // The export's date columns use DD-MM-YYYY (e.g. "19-01-2027")
            // — explicit format, not a free-form parse, since a dash-
            // separated ambiguous date could otherwise be misread.
            return Carbon::createFromFormat('d-m-Y', trim((string) $value))->toDateString();
        } catch (\Throwable) {
            try {
                return Carbon::parse((string) $value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function toDateTime(mixed $value): ?string
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

    private function toMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Export formats currency as "SAR 25,000.00" — strip everything but
        // digits/decimal point/minus sign.
        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $cleaned === '' || $cleaned === '-' ? null : (float) $cleaned;
    }
}
