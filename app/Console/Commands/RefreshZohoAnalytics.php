<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * One refresh cycle of the Zoho Analytics pipeline, for the scheduler:
 * pull only the rows changed since the last run (zoho:sync-raw, every table incremental — never a full export,
 * to stay well inside the daily API-units quota), then run Dr. Ahmed's queries on them (zoho:compute-queries).
 *
 *   hourly  : changed rows + the hourly queries (AR SALES, AR Risk, Sales Orders Not Invoiced, Sales Item Performance)
 *   --nightly: the same, then the nightly classification queries
 *   both end with zoho:feed-legacy-tables (ar_ledger_entries / sales_item_performance for the older pages)
 *
 * The queries run even if a table's pull failed: they rebuild from whatever is stored, via staging + swap, and
 * a failed pull leaves its table unchanged.
 */
class RefreshZohoAnalytics extends Command
{
    protected $signature = 'zoho:refresh {--nightly : Also run the nightly classification queries}';

    protected $description = 'Hourly Zoho Analytics refresh: incremental raw-table pull + Dr. Ahmed\'s queries';

    public function handle(): int
    {
        $ok = true;
        $this->info('zoho:sync-raw --group=incremental');
        $ok = Artisan::call('zoho:sync-raw', ['--group' => 'incremental', '--no-full' => true], $this->output) === self::SUCCESS && $ok;

        $this->info('zoho:compute-queries --group=hourly');
        $ok = Artisan::call('zoho:compute-queries', ['--group' => 'hourly'], $this->output) === self::SUCCESS && $ok;

        if ($this->option('nightly')) {
            $this->info('zoho:compute-queries --group=nightly');
            $ok = Artisan::call('zoho:compute-queries', ['--group' => 'nightly'], $this->output) === self::SUCCESS && $ok;
        }

        // The older tables some pages still read (ar_ledger_entries, sales_item_performance), from the results above.
        $this->info('zoho:feed-legacy-tables');
        $ok = Artisan::call('zoho:feed-legacy-tables', [], $this->output) === self::SUCCESS && $ok;

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
