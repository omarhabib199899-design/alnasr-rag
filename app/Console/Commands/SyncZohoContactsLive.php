<?php

namespace App\Console\Commands;

use App\Jobs\ZohoContactSyncJob;
use App\Models\Import;
use App\Models\ZohoConnection;
use Illuminate\Console\Command;

/**
 * Manual trigger for ZohoContactSyncJob — kept OUT of ZohoSyncService's
 * hourly run for now, deliberately, until the ZohoInventory.contacts.READ
 * scope is confirmed live via a reconnect (see ZohoOAuthClient's SCOPE
 * comment). Dispatching this before that reconnect would just fail with a
 * 401 on every contact and burn API budget for nothing.
 */
class SyncZohoContactsLive extends Command
{
    protected $signature = 'reports:sync-contacts-live';

    protected $description = 'Dispatch a live Zoho Contacts API sync (requires the ZohoInventory.contacts.READ scope — reconnect first)';

    public function handle(): int
    {
        $connection = ZohoConnection::where('status', 'connected')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        // Same guard ZohoSyncService uses for the other types — without it,
        // re-running this command while a chain is already mid-flight
        // starts a second one, and both read/write the same ZohoSyncCursor
        // row concurrently. Confirmed live (2026-09-22): running this twice
        // in a row produced two simultaneous 'processing' Import rows
        // (row_count 165 and 30) and burned extra API budget re-fetching
        // the same contacts.
        $alreadyRunning = Import::where('user_id', $connection->user_id)
            ->where('type', 'contacts')
            ->where('source', 'zoho')
            ->where('status', 'processing')
            ->where('updated_at', '>', now()->subMinutes(20))
            ->exists();

        if ($alreadyRunning) {
            $this->info('A contacts sync chain is already running — not starting a second one.');

            return self::SUCCESS;
        }

        ZohoContactSyncJob::dispatch($connection);

        $this->info('Queued. Runs in the background via the scheduled queue worker.');

        return self::SUCCESS;
    }
}
