<?php

namespace App\Console\Commands;

use App\Models\CustomerCreditUsage;
use Illuminate\Console\Command;

/**
 * Imports CREDIT_LIMIT_USAGE.csv — a Zoho Analytics Query Table, computed
 * from AR Ledger Master. One row per customer. Small file (~1,472 rows),
 * plain CSV read (no streaming needed).
 *
 * Upserts per customer (updateOrCreate) rather than truncate+reinsert —
 * already safe against a mid-import interruption on its own (a killed run
 * just leaves fewer rows updated, never an empty/half-replaced table), so
 * unlike ImportSalesItemPerformance this doesn't need a staging-table swap.
 *
 * importFromFile() is the reusable core (also used by
 * SyncCustomerCreditUsageJob, which pulls this same view live via the Zoho
 * Analytics Bulk API instead of a manually-dropped file) — kept static so
 * it needs no Command instance.
 */
class ImportCustomerCreditUsage extends Command
{
    protected $signature = 'reports:import-customer-credit-usage {path : Absolute path to CREDIT_LIMIT_USAGE.csv}';

    protected $description = 'Import (or refresh) customer credit limit/usage from a CREDIT_LIMIT_USAGE.csv export';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        try {
            $imported = self::importFromFile($path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done — {$imported} customer credit rows imported/updated.");

        return self::SUCCESS;
    }

    public static function importFromFile(string $path): int
    {
        $handle = fopen($path, 'r');

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $columnIndex = array_flip(array_map('trim', $headerRow));

            $required = ['Customer ID', 'Customer Name'];
            foreach ($required as $field) {
                if (! isset($columnIndex[$field])) {
                    throw new \RuntimeException("Missing required column: {$field}");
                }
            }

            $now = now();
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $customerId = self::toBigIntString($row[$columnIndex['Customer ID']] ?? null);
                $customerName = trim((string) ($row[$columnIndex['Customer Name']] ?? ''));

                if ($customerId === null || $customerName === '') {
                    continue;
                }

                CustomerCreditUsage::updateOrCreate(
                    ['zoho_customer_id' => $customerId],
                    [
                        'customer_number' => self::cell($row, $columnIndex, 'Customer Number'),
                        'customer_name' => $customerName,
                        'management' => self::cell($row, $columnIndex, 'Management'),
                        'rep_name' => self::cell($row, $columnIndex, 'Rep Name'),
                        'credit_limit' => self::toFloat(self::cell($row, $columnIndex, 'Credit Limit')),
                        'used_credit' => self::toFloat(self::cell($row, $columnIndex, 'Used Credit')),
                        'credit_utilization_pct' => self::toFloat(self::cell($row, $columnIndex, 'Credit Utilization %')),
                        'remaining_credit' => self::toFloat(self::cell($row, $columnIndex, 'Remaining Credit')),
                        'available_credit_pct' => self::toFloat(self::cell($row, $columnIndex, 'Available Credit %')),
                        'credit_status' => self::cell($row, $columnIndex, 'Credit Status'),
                        'updated_at' => $now,
                    ],
                );
                $imported++;
            }

            return $imported;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public static function cell(array $row, array $columnIndex, string $field): ?string
    {
        if (! isset($columnIndex[$field])) {
            return null;
        }
        $value = trim((string) ($row[$columnIndex[$field]] ?? ''));

        return $value === '' ? null : $value;
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

    public static function toFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $cleaned = preg_replace('/[^0-9.\-]/', '', $value);

        return $cleaned === '' || $cleaned === '-' ? null : (float) $cleaned;
    }
}
