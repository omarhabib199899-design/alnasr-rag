<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair for customers.extra rows written by
 * ImportCustomersAnalyticsReport BEFORE its double-JSON-encoding bug was
 * fixed (2026-09-22) — same class of bug as FixDoubleEncodedContactData,
 * just a different column/command. Those rows' extra decodes to a STRING
 * (the real JSON, still encoded) instead of an array, so every extra-sourced
 * field on the customer detail page (monthly sales/collection targets,
 * annual targets, price list id, management, etc.) silently showed empty.
 *
 * Reads/writes the raw column via the query builder rather than Eloquent,
 * specifically to avoid the same cast-triggered re-encoding this is fixing.
 */
class FixDoubleEncodedCustomerExtra extends Command
{
    protected $signature = 'reports:fix-double-encoded-customer-extra';

    protected $description = 'Repair customers.extra rows that were double-JSON-encoded before the 2026-09-22 fix';

    public function handle(): int
    {
        $fixed = 0;
        $alreadyFine = 0;

        DB::table('customers')
            ->whereNotNull('extra')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$fixed, &$alreadyFine) {
                foreach ($rows as $row) {
                    $once = json_decode($row->extra, true);

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
                        'extra' => json_encode($twice),
                    ]);
                    $fixed++;
                }
            });

        $this->info("Done — {$fixed} rows repaired, {$alreadyFine} were already correctly encoded.");

        return self::SUCCESS;
    }
}
