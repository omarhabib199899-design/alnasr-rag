<?php

namespace App\Console\Commands;

use App\Jobs\SfdaPriceCheckJob;
use Illuminate\Console\Command;

/**
 * Kicks off the SFDA price comparison — see SfdaPriceCheckJob for the actual
 * work. This command used to run the whole comparison inline and got
 * SIGKILLed by the host partway through a ~12k-item run (2026-09-14),
 * losing every result since nothing was persisted until the loop finished.
 * Now it just enqueues the first batch; the queue worker (already running
 * every minute per routes/console.php) processes it in small chunks that
 * each finish well within the host's limits, with results written
 * incrementally so a kill mid-run only loses the batch in flight.
 */
class CheckSfdaPrices extends Command
{
    protected $signature = 'reports:check-sfda-prices';

    protected $description = "Queue a background comparison of our item prices against SFDA's official regulated prices";

    public function handle(): int
    {
        SfdaPriceCheckJob::dispatch();

        $this->info('Queued. Processes in the background in small batches via the scheduled queue worker — check /reports/sfda-price-checks in a few minutes.');

        return self::SUCCESS;
    }
}
