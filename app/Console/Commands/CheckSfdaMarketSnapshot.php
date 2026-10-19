<?php

namespace App\Console\Commands;

use App\Jobs\SfdaMarketSnapshotJob;
use Illuminate\Console\Command;

/**
 * Kicks off the daily full-market SFDA snapshot — see SfdaMarketSnapshotJob
 * for the actual work. Same enqueue-first-batch-only pattern as
 * reports:check-sfda-prices, for the same reason (one long blocking run
 * risks a host SIGKILL losing everything).
 */
class CheckSfdaMarketSnapshot extends Command
{
    protected $signature = 'reports:check-sfda-market-snapshot';

    protected $description = "Queue a full daily snapshot of SFDA's entire drug database (not just our own inventory) and diff it against yesterday's";

    public function handle(): int
    {
        SfdaMarketSnapshotJob::dispatch();

        $this->info('Queued. Processes in the background in small batches via the scheduled queue worker — check /reports/sfda-market-changes once it finishes (~459 pages, runs for a while).');

        return self::SUCCESS;
    }
}
