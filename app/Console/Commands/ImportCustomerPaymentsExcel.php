<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * One-time seed import for customer_payments from a direct Zoho export
 * (user-downloaded "Payments Received.xlsx", 2026-10-14) — fast-forwards
 * the table to real, complete data immediately instead of waiting for
 * CustomerPaymentSyncJob's live API backfill (~77 calls, but sharing a
 * heavily oversubscribed hourly budget with 9 other sync chains) to catch
 * up on its own.
 *
 * The export's layout isn't a plain header-row sheet: row 1 is a merged
 * report-title banner ("Payments Received\n\nFrom ... To ..."), row 2 is
 * the real header row — and conveniently, those headers are Zoho's own raw
 * API field names (payment_id, payment_number, invoice_number, date,
 * payment_mode, amount, unused_amount, reference_number, customer_id,
 * customer_name, ...), not display labels — confirmed live 2026-10-14
 * against the same org's live GET /inventory/v1/customerpayments response,
 * field for field. 15,397 rows, 15,397 unique payment_id values, zero
 * duplicates.
 *
 * Upserts by zoho_payment_id — the exact same unique key
 * CustomerPaymentSyncJob uses — so the live sync (left running) and this
 * one-time import can never double-insert the same payment; the live sync
 * just refreshes zoho_created_time/zoho_last_modified_time (not present in
 * this export) as it naturally reaches each row, and picks up anything
 * created after this export's own cutoff.
 */
class ImportCustomerPaymentsExcel extends Command
{
    protected $signature = 'reports:import-customer-payments-excel {path : Absolute path to the exported .xlsx file}';

    protected $description = 'One-time seed import of customer_payments from a direct Zoho Payments Received export';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->info('Loading spreadsheet...');
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $grid = $sheet->toArray(null, true, true, false);

        if (count($grid) < 3) {
            $this->error('Sheet has fewer than 3 rows — expected a title row, a header row, then data.');

            return self::FAILURE;
        }

        // Row 0 is the merged title banner, deliberately skipped.
        $headers = array_map(fn ($h) => trim((string) $h), $grid[1]);
        $columnIndex = array_flip($headers);

        $required = ['payment_id', 'payment_number', 'date', 'payment_mode', 'amount', 'unused_amount', 'reference_number', 'invoice_number', 'customer_id', 'customer_name'];
        foreach ($required as $field) {
            if (! isset($columnIndex[$field])) {
                $this->error("Expected column '{$field}' not found in row 2 — found: ".implode(', ', $headers));

                return self::FAILURE;
            }
        }

        $imported = 0;
        $skipped = 0;
        $now = now();

        // Maps every column this table has a dedicated field for to its
        // exact Excel header name — covers every real column in the export
        // (confirmed live 2026-10-14: txn_posting_date/last_four_digits/
        // gateway_transaction_id/documents/tax_account_id/tax_account_name/
        // payment_method/location_name are blank on every single row in
        // this export, but stored anyway per explicit ask to use every
        // column, not just the ones with data today).
        $fieldToHeader = [
            'payment_number' => 'payment_number',
            'payment_date' => 'date',
            'txn_posting_date' => 'txn_posting_date',
            'zoho_customer_id' => 'customer_id',
            'customer_name' => 'customer_name',
            'amount' => 'amount',
            'bcy_amount' => 'bcy_amount',
            'unused_amount' => 'unused_amount',
            'bcy_unused_amount' => 'bcy_unused_amount',
            'payment_mode' => 'payment_mode',
            'reference_number' => 'reference_number',
            'invoice_numbers' => 'invoice_number',
            'account_id' => 'account_id',
            'account_name' => 'account_name',
            'description' => 'description',
            'last_four_digits' => 'last_four_digits',
            'gateway_transaction_id' => 'gateway_transaction_id',
            'bcy_refunded_amount' => 'bcy_refunded_amount',
            'tax_account_id' => 'tax_account_id',
            'tax_account_name' => 'tax_account_name',
            'tax_amount_withheld' => 'tax_amount_withheld',
            'currency_code' => 'currency_code',
            'payment_method' => 'payment_method',
            'location_name' => 'location_name',
        ];

        for ($i = 2; $i < count($grid); $i++) {
            $row = $grid[$i];
            $paymentId = trim((string) ($row[$columnIndex['payment_id']] ?? ''));

            if ($paymentId === '') {
                $skipped++;

                continue;
            }

            $date = $row[$columnIndex['date']] ?? null;
            $date = $date !== null && $date !== '' ? (string) $date : null;

            $values = [
                'payment_date' => $date,
                'has_attachment' => isset($columnIndex['has_attachment']) ? (int) filter_var($row[$columnIndex['has_attachment']] ?? false, FILTER_VALIDATE_BOOLEAN) : null,
                'updated_at' => $now,
                'created_at' => $now,
            ];
            foreach ($fieldToHeader as $field => $header) {
                if ($field === 'payment_date') {
                    continue;
                }
                $raw = $row[$columnIndex[$header]] ?? null;
                $values[$field] = $field === 'zoho_customer_id' && $raw ? (string) $raw : ($raw ?: null);
            }

            $rawRowAssoc = [];
            foreach ($headers as $idx => $header) {
                if ($header !== '') {
                    $rawRowAssoc[$header] = $row[$idx] ?? null;
                }
            }
            $values['extra'] = json_encode($rawRowAssoc);

            DB::table('customer_payments')->updateOrInsert(
                ['zoho_payment_id' => $paymentId],
                $values,
            );
            $imported++;
        }

        $this->info("Imported/updated {$imported} payments, skipped {$skipped} blank rows.");

        return self::SUCCESS;
    }
}
