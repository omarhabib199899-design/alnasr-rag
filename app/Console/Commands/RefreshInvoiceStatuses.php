<?php

namespace App\Console\Commands;

use App\Jobs\RefreshInvoiceStatusJob;
use App\Models\ZohoConnection;
use Illuminate\Console\Command;

/**
 * Kicks off RefreshInvoiceStatusJob — see that class for why invoice_items
 * status needs correcting and why this is cheap enough to run as one sweep.
 */
class RefreshInvoiceStatuses extends Command
{
    protected $signature = 'reports:refresh-invoice-statuses';

    protected $description = "Correct invoice_items.status against Zoho's real current status for every invoice";

    public function handle(): int
    {
        $connection = ZohoConnection::where('status', 'connected')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        RefreshInvoiceStatusJob::dispatch($connection);

        $this->info('Queued. Runs in the background via the scheduled queue worker (~206 pages, well within the API budget) — check /reports/invoices in a while.');

        return self::SUCCESS;
    }
}
