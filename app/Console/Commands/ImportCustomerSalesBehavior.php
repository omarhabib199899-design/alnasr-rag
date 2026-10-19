<?php

namespace App\Console\Commands;

use App\Models\CustomerSalesBehavior;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Imports Customer_Sales_Behavior_Classification.csv — a Zoho Analytics
 * Query Table export. Mainly needed for historical_first_purchase (not
 * captured anywhere else in this project) and the per-dimension "Why"
 * explanations the combined decision-engine table only summarizes into one
 * Final Why. Everything else from this export goes into `extra`.
 *
 * Upserts per customer (updateOrCreate), never truncates — safe against a
 * mid-import interruption on its own, same as ImportCustomerCreditUsage.
 *
 * importFromFile() is the reusable core (also used by
 * SyncCustomerSalesBehavior, which pulls this same view live via the Zoho
 * Analytics Bulk API instead of a manually-dropped file).
 */
class ImportCustomerSalesBehavior extends Command
{
    protected $signature = 'reports:import-customer-sales-behavior {path : Absolute path to Customer_Sales_Behavior_Classification.csv}';

    protected $description = 'Import (or refresh) customer sales behavior detail from a Customer_Sales_Behavior_Classification.csv export';

    /** Fields kept as dedicated columns; everything else in the CSV goes into extra. */
    private const DEDICATED_FIELDS = ['Customer ID', 'Customer Name', 'Historical First Purchase', 'Historical Last Purchase', 'Sales Growth Why', 'Buying Rhythm Why', 'AOV Growth Why'];

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

        $this->info("Done — {$imported} customer sales behavior rows imported/updated.");

        return self::SUCCESS;
    }

    public static function importFromFile(string $path): int
    {
        $handle = fopen($path, 'r');

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $headerRow = array_map('trim', $headerRow);
            $columnIndex = array_flip($headerRow);

            if (! isset($columnIndex['Customer ID'])) {
                throw new \RuntimeException('Missing required column: Customer ID');
            }

            $now = now();
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $customerId = self::toBigIntString($row[$columnIndex['Customer ID']] ?? null);
                $customerName = trim((string) ($row[$columnIndex['Customer Name']] ?? ''));

                if ($customerId === null || $customerName === '') {
                    continue;
                }

                $extra = [];
                foreach ($headerRow as $field) {
                    if (in_array($field, self::DEDICATED_FIELDS, true)) {
                        continue;
                    }
                    $value = trim((string) ($row[$columnIndex[$field]] ?? ''));
                    $extra[$field] = $value === '' ? null : $value;
                }

                CustomerSalesBehavior::updateOrCreate(
                    ['zoho_customer_id' => $customerId],
                    [
                        'customer_name' => $customerName,
                        'historical_first_purchase' => self::toDate(self::cell($row, $columnIndex, 'Historical First Purchase')),
                        'historical_last_purchase' => self::toDate(self::cell($row, $columnIndex, 'Historical Last Purchase')),
                        'sales_growth_why' => self::cell($row, $columnIndex, 'Sales Growth Why'),
                        'buying_rhythm_why' => self::cell($row, $columnIndex, 'Buying Rhythm Why'),
                        'aov_growth_why' => self::cell($row, $columnIndex, 'AOV Growth Why'),
                        'extra' => $extra,
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

    public static function toDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $value)->toDateString();
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
