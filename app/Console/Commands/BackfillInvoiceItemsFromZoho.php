<?php

namespace App\Console\Commands;

use App\Jobs\BackfillInvoiceItemsFromZohoJob;
use App\Models\Import;
use App\Models\ZohoConnection;
use Illuminate\Console\Command;

/**
 * Manual one-time trigger for BackfillInvoiceItemsFromZohoJob — see that
 * job's docblock for the full picture (41,142 invoices as of 2026-09-22
 * confirmed with no zoho_document_id, i.e. only ever imported from the old
 * Analytics Excel export, missing warehouse/discount_amount/sub_total and
 * an invoice_documents row). Not part of ZohoSyncService's hourly run — this
 * is a self-terminating chain (stops once no invoice_items row has a null
 * zoho_document_id left) rather than a recurring type, so it doesn't belong
 * in that loop.
 */
class BackfillInvoiceItemsFromZoho extends Command
{
    protected $signature = 'reports:backfill-invoice-items';

    protected $description = 'Dispatch a background backfill of pre-live-sync invoice_items (adds warehouse/discount detail + invoice_documents rows), throttled on the same shared Zoho API budget as everything else';

    public function handle(): int
    {
        $connection = ZohoConnection::where('status', 'connected')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $alreadyRunning = Import::where('user_id', $connection->user_id)
            ->where('type', 'invoice_items_backfill')
            ->where('source', 'zoho')
            ->where('status', 'processing')
            ->where('updated_at', '>', now()->subMinutes(20))
            ->exists();

        if ($alreadyRunning) {
            $this->info('A backfill chain is already running — not starting a second one.');

            return self::SUCCESS;
        }

        BackfillInvoiceItemsFromZohoJob::dispatch($connection);

        $this->info('Queued. Runs in the background sharing the normal Zoho API throttle — expect several days to fully drain.');

        return self::SUCCESS;
    }
}
