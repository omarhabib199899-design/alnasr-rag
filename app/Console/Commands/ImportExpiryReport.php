<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoCustomModuleClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills purchase_recommendations.expired_quantity from Zoho's own
 * "cm_expiry_report" custom module — built by this org's existing Deluge
 * automation (a scheduled function that walks every item's batches and logs
 * ones expiring within 3 months, bucketing each into "Already Expired",
 * "1 Month", etc.). Read live via the API rather than a manual export: at
 * ~572 records total this is small enough that there's no reason to make
 * the user do a manual Zoho-report export/upload round trip the way the
 * much larger Purchase Orders/Stock Movement backfills needed.
 *
 * Only "Already Expired" rows count toward expired_quantity — the module
 * also holds not-yet-expired batches (1/2/3 Month buckets), which aren't
 * this column's concern.
 */
class ImportExpiryReport extends Command
{
    protected $signature = 'reports:import-expiry-report';

    protected $description = "Sum Zoho's cm_expiry_report \"Already Expired\" batches per SKU into purchase_recommendations.expired_quantity";

    private const MODULE_NAME = 'cm_expiry_report';

    public function handle(ZohoCustomModuleClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->first();

        if ($connection === null) {
            $this->error('No connected Zoho account found.');

            return self::FAILURE;
        }

        $records = $client->listAll($connection, self::MODULE_NAME);
        $this->info('Fetched '.count($records).' records from '.self::MODULE_NAME.'.');

        $expiredBySku = [];

        foreach ($records as $record) {
            $bucket = $record['cf_expiry_bucket'] ?? null;

            if ($bucket !== 'Already Expired') {
                continue;
            }

            $sku = trim((string) ($record['cf_sku'] ?? ''));

            if ($sku === '') {
                continue;
            }

            $quantity = (int) ($record['cf_balance_quantity'] ?? 0);
            $expiredBySku[$sku] = ($expiredBySku[$sku] ?? 0) + $quantity;
        }

        $this->info(count($expiredBySku).' SKUs have already-expired stock.');

        $now = now();
        $updated = 0;

        foreach ($expiredBySku as $sku => $quantity) {
            $updated += DB::table('purchase_recommendations')
                ->where('sku', $sku)
                ->update(['expired_quantity' => $quantity, 'updated_at' => $now]);
        }

        $this->info("Updated {$updated} purchase_recommendations rows.");

        return self::SUCCESS;
    }
}
