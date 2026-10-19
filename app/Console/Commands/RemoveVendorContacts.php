<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time cleanup: ZohoContactClient::fetchPage() originally queried
 * /inventory/v1/contacts with no contact_type filter, so Zoho's live
 * Contacts sync pulled in vendors alongside real customers (confirmed
 * live 2026-09-22: 177 of 1,647 rows had contact_type "vendor"). Now
 * fixed with an explicit contact_type=customer request param going
 * forward; this command removes the vendor rows already written.
 */
class RemoveVendorContacts extends Command
{
    protected $signature = 'reports:remove-vendor-contacts';

    protected $description = 'Delete customers rows whose live_contact_data.contact_type is "vendor" (wrongly synced before the contact_type filter fix)';

    public function handle(): int
    {
        $ids = DB::table('customers')
            ->whereNotNull('live_contact_data')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(live_contact_data, '$.contact_type')) = 'vendor'")
            ->pluck('id');

        $count = $ids->count();

        DB::table('customers')->whereIn('id', $ids)->delete();

        $this->info("Deleted {$count} vendor rows from customers.");
        $this->info('Remaining customers: '.DB::table('customers')->count());

        return self::SUCCESS;
    }
}
