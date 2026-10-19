<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair for rows written by ZohoContactSyncJob BEFORE its
 * double-JSON-encoding bug was fixed (2026-09-22) — those rows'
 * live_contact_data decodes to a STRING (the real JSON, still encoded)
 * instead of an array. ExtractContactFields already guards against this
 * (skips anything that isn't an array after decoding), so those customers
 * silently never got their owner/currency/address/etc. columns populated.
 *
 * Reads/writes the raw column via the query builder rather than Eloquent,
 * specifically to avoid the same cast-triggered re-encoding this is fixing.
 */
class FixDoubleEncodedContactData extends Command
{
    protected $signature = 'reports:fix-double-encoded-contacts';

    protected $description = 'Repair customers.live_contact_data rows that were double-JSON-encoded before the 2026-09-22 fix';

    public function handle(): int
    {
        $fixed = 0;
        $alreadyFine = 0;

        DB::table('customers')
            ->whereNotNull('live_contact_data')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$fixed, &$alreadyFine) {
                foreach ($rows as $row) {
                    $once = json_decode($row->live_contact_data, true);

                    if (is_array($once)) {
                        $alreadyFine++;

                        continue;
                    }

                    if (! is_string($once)) {
                        continue;
                    }

                    $twice = json_decode($once, true);

                    if (! is_array($twice)) {
                        continue;
                    }

                    DB::table('customers')->where('id', $row->id)->update([
                        'live_contact_data' => json_encode($twice),
                    ]);
                    $fixed++;
                }
            });

        $this->info("Done — {$fixed} rows repaired, {$alreadyFine} were already correctly encoded.");
        $this->info('Now run: php artisan reports:extract-contact-fields');

        return self::SUCCESS;
    }
}
