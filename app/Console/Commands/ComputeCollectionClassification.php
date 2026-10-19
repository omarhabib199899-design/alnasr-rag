<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replaces Zoho Analytics' "Collection Classification" Query Table (see
 * SyncCustomerClassifications, type=collection) with our own computation,
 * run against our own live copy of its "Collection_Classification_Staging"
 * view (see SyncCollectionClassificationStaging) — full SQL pasted by the
 * user (2026-10-06) matches this file's logic exactly, including the
 * day-of-month boundaries.
 *
 * Only a customer's 5 most-recently-settled invoices (CLOSED BY PAYMENT
 * only — write-offs/credit-note closures don't count as "paid") drive the
 * classification: it answers "when they did pay, how promptly?", not "are
 * they current right now" (that's AR Risk / Due Ages' job). Median Settlement
 * Delay Days over those 5 invoices:
 *   <=15  On Time / Perfect
 *   <=30  Late
 *   <=60  Very Late
 *   else  Severe Delay
 * Payment Timing Pattern is a separate signal (which third of the month they
 * tend to settle in) over the same 5 invoices, majority (>50%) wins, else
 * Random / Mixed.
 *
 * A customer with zero qualifying invoices (never paid, or every paid
 * invoice is missing Settlement Date/Delay Days in the source) gets no row
 * here at all — same "absent, not misclassified" choice SFDA/Documents make
 * elsewhere, rather than inventing a classification with nothing behind it.
 */
class ComputeCollectionClassification extends Command
{
    protected $signature = 'reports:compute-collection-classification';

    protected $description = 'Compute Collection Classification ourselves from collection_classification_staging (replaces the Zoho Analytics "collection" Query Table import)';

    public function handle(): int
    {
        $this->info('Loading qualifying settled invoices from collection_classification_staging...');

        $rows = DB::table('collection_classification_staging')
            ->where('closing_method', 'CLOSED BY PAYMENT')
            ->whereNotNull('settlement_date')
            ->whereNotNull('settlement_delay_days')
            ->orderBy('zoho_customer_id')
            ->orderByDesc('settlement_date')
            ->orderByDesc('zoho_invoice_id')
            ->get(['zoho_customer_id', 'customer_number', 'customer_name', 'zoho_invoice_id', 'settlement_date', 'settlement_delay_days']);

        $this->info('Qualifying rows: '.$rows->count());

        // groupBy() preserves each row's relative order within its group —
        // the query above already sorted settlement_date DESC, zoho_invoice_id
        // DESC per customer, so take(5) here is exactly the SQL's
        // "ROW_NUMBER() OVER(PARTITION BY Customer ID ORDER BY Settlement
        // Date DESC, Invoice ID DESC) <= 5".
        $byCustomer = $rows->groupBy('zoho_customer_id');

        $now = now();
        $batch = [];

        foreach ($byCustomer as $customerRows) {
            $top5 = $customerRows->take(5);
            $delays = $top5->pluck('settlement_delay_days')->map(fn ($v) => (int) $v);
            $medianDelay = $this->medianOf($delays);

            $classification = match (true) {
                $medianDelay <= 15 => 'On Time / Perfect',
                $medianDelay <= 30 => 'Late',
                $medianDelay <= 60 => 'Very Late',
                default => 'Severe Delay',
            };

            $early = 0;
            $mid = 0;
            $late = 0;
            foreach ($top5 as $row) {
                $day = Carbon::parse($row->settlement_date)->day;
                if ($day >= 1 && $day <= 10) {
                    $early++;
                } elseif ($day >= 11 && $day <= 20) {
                    $mid++;
                } else {
                    $late++;
                }
            }

            $invoicesUsed = $top5->count();
            $half = $invoicesUsed / 2;
            $pattern = match (true) {
                $early > $half => 'Early Month',
                $mid > $half => 'Mid Month',
                $late > $half => 'Late Month',
                default => 'Random / Mixed',
            };

            $first = $top5->first();

            $batch[] = [
                'customer_number' => $first->customer_number,
                'customer_name' => $first->customer_name,
                'type' => 'collection',
                'classification' => $classification,
                'data' => json_encode([
                    'invoices_used' => $invoicesUsed,
                    'last_settlement_date' => (string) $first->settlement_date,
                    'median_settlement_delay_days' => $medianDelay,
                    'early_month_count' => $early,
                    'mid_month_count' => $mid,
                    'late_month_count' => $late,
                    'payment_timing_pattern' => $pattern,
                ]),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('customer_classifications')->upsert(
                $chunk,
                ['customer_number', 'type'],
                ['customer_name', 'classification', 'data', 'synced_at', 'updated_at']
            );
        }

        $this->info('Done — '.count($batch).' collection classifications computed and written.');

        return self::SUCCESS;
    }

    /**
     * Zoho Analytics' MEDIAN(): the middle value for an odd count, the
     * average of the two middle values for an even count — same convention
     * used elsewhere in this app (e.g. CustomerController::medianOf()).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $values
     */
    private function medianOf(\Illuminate\Support\Collection $values): float
    {
        $sorted = $values->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 === 1 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }
}
