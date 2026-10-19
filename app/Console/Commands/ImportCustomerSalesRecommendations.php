<?php

namespace App\Console\Commands;

use App\Models\CustomerSalesRecommendation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Imports Final_Sales_Decision_Engine.csv — the combined, per-customer
 * output of Zoho Analytics' "Customer Sales Behavior & Decision Engine":
 * all five segment classifications plus the synthesized recommendation.
 * Drives the "Elnasr Recommendations" tab.
 *
 * Upserts per customer (updateOrCreate), never truncates — safe against a
 * mid-import interruption on its own, same as ImportCustomerCreditUsage.
 *
 * importFromFile() is the reusable core (also used by
 * SyncCustomerSalesRecommendations, which pulls this same view live via
 * the Zoho Analytics Bulk API instead of a manually-dropped file).
 */
class ImportCustomerSalesRecommendations extends Command
{
    protected $signature = 'reports:import-customer-sales-recommendations {path : Absolute path to Final_Sales_Decision_Engine.csv}';

    protected $description = 'Import (or refresh) customer sales recommendations from a Final_Sales_Decision_Engine.csv export';

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

        $this->info("Done — {$imported} customer sales recommendations imported/updated.");

        return self::SUCCESS;
    }

    public static function importFromFile(string $path): int
    {
        $handle = fopen($path, 'r');

        try {
            $headerRow = fgetcsv($handle);
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $columnIndex = array_flip(array_map('trim', $headerRow));

            if (! isset($columnIndex['D.Customer ID'])) {
                throw new \RuntimeException('Missing required column: D.Customer ID');
            }

            $now = now();
            $imported = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $customerId = self::toBigIntString($row[$columnIndex['D.Customer ID']] ?? null);
                $customerName = trim((string) ($row[$columnIndex['D.Customer Name']] ?? ''));

                if ($customerId === null || $customerName === '') {
                    continue;
                }

                CustomerSalesRecommendation::updateOrCreate(
                    ['zoho_customer_id' => $customerId],
                    [
                        'customer_name' => $customerName,
                        'sales_growth_segment' => self::cell($row, $columnIndex, 'D.Sales Growth Segment'),
                        'buying_rhythm_segment' => self::cell($row, $columnIndex, 'D.Buying Rhythm Segment'),
                        'aov_growth_segment' => self::cell($row, $columnIndex, 'D.AOV Growth Segment'),
                        'sku_growth_segment' => self::cell($row, $columnIndex, 'D.SKU Growth Segment'),
                        'portfolio_behavior_segment' => self::cell($row, $columnIndex, 'D.Portfolio Behavior Segment'),
                        'last_purchase_date' => self::toDate(self::cell($row, $columnIndex, 'D.Last Purchase Date')),
                        'days_since_last_purchase' => self::toInt(self::cell($row, $columnIndex, 'D.Days Since Last Purchase')),
                        'priority_level' => self::cell($row, $columnIndex, 'Priority Level'),
                        'overall_sales_status' => self::cell($row, $columnIndex, 'Overall Sales Status'),
                        'primary_recommendation' => self::cell($row, $columnIndex, 'Primary Recommendation'),
                        'secondary_recommendation' => self::cell($row, $columnIndex, 'Secondary Recommendation'),
                        'final_why' => self::cell($row, $columnIndex, 'Final Why'),
                        'recommended_sales_rep_action' => self::cell($row, $columnIndex, 'Recommended Sales Rep Action'),
                        'key_risk' => self::cell($row, $columnIndex, 'Key Risk'),
                        'key_opportunity' => self::cell($row, $columnIndex, 'Key Opportunity'),
                        'confidence' => self::cell($row, $columnIndex, 'Confidence / Data Sufficiency'),
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

    public static function toInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) preg_replace('/[^0-9.\-]/', '', $value));
    }
}
