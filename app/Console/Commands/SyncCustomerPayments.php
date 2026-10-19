<?php

namespace App\Console\Commands;

use App\Jobs\CustomerPaymentSyncJob;
use App\Models\ZohoConnection;
use Illuminate\Console\Command;

/**
 * Manual trigger for CustomerPaymentSyncJob — for testing right after the
 * ZohoInventory.customerpayments.READ scope goes live (reconnect required
 * first), without waiting for the next hourly ZohoSyncService tick.
 */
class SyncCustomerPayments extends Command
{
    protected $signature = 'reports:sync-customer-payments';

    protected $description = 'Dispatch CustomerPaymentSyncJob for the connected Zoho account';

    public function handle(): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        CustomerPaymentSyncJob::dispatch($connection);
        $this->info('CustomerPaymentSyncJob dispatched.');

        return self::SUCCESS;
    }
}
