<?php

namespace App\Jobs;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a Zoho sync off the request cycle. Both the first sync right after
 * OAuth connect and a manual "Sync Now" click used to call
 * ZohoSyncService::sync() synchronously inside the HTTP request — fine
 * while the account only had a handful of records, but a real historical
 * backfill (years of sales orders/invoices/purchase orders/returns) can
 * run well past any request timeout. ZohoSyncService itself already
 * records success/failure on the ZohoConnection and each Import row, so
 * there's nothing left for this job to report beyond letting failures
 * surface through the normal queue failed-job path.
 */
class SyncZohoConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly ZohoConnection $zohoConnection) {}

    public function handle(ZohoSyncService $syncService): void
    {
        $syncService->sync($this->zohoConnection);
    }
}
