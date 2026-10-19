<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints a sample of customers with all 4 classifications side by side, for
 * manually spot-checking our synced customer_classifications rows against
 * the live Sales_Classification/Collection_Classification/
 * Profitability_Classification/Documents_Classification tables in Zoho
 * Analytics — a sync-integrity check (did we copy the data correctly), not
 * a formula-correctness check (the formula is Dr. Ahmed's own, unchanged).
 */
class SampleCustomerClassifications extends Command
{
    protected $signature = 'reports:sample-customer-classifications {--limit=19} {--random : Pick a random sample instead of the first N by customer_number}';

    protected $description = 'Print a sample of customers with all 4 classifications for manual spot-checking against Zoho Analytics';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $customerNumbers = DB::table('customer_classifications')
            ->select('customer_number')
            ->distinct()
            ->when($this->option('random'), fn ($q) => $q->inRandomOrder(), fn ($q) => $q->orderBy('customer_number'))
            ->limit($limit)
            ->pluck('customer_number');

        $rows = DB::table('customer_classifications')
            ->whereIn('customer_number', $customerNumbers)
            ->get()
            ->groupBy('customer_number');

        foreach ($customerNumbers as $number) {
            $byType = $rows->get($number, collect())->keyBy('type');
            $name = $byType->first()?->customer_name ?? '?';

            $this->info("=== {$number} — {$name} ===");
            $this->line('  Sales:          '.($byType->get('sales')?->classification ?? '—'));
            $this->line('  Collection:     '.($byType->get('collection')?->classification ?? '—'));
            $this->line('  Profitability:  '.($byType->get('profitability')?->classification ?? '—'));
            $this->line('  Documents:      '.($byType->get('documents')?->classification ?? '—'));
            $this->newLine();
        }

        $this->info('Total sampled: '.$customerNumbers->count());

        return self::SUCCESS;
    }
}
