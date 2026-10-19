<?php

namespace App\Jobs;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoTransactionClient;
use App\Services\Zoho\ZohoTransactionMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time (re-runnable) cleanup: invoice_items.status is wrong for almost
 * every historical row — ImportInvoiceItemsFixedReport hardcoded every row
 * to 'completed' regardless of the invoice's real Zoho status, because the
 * Analytics Query Table it read from didn't expose a proper per-invoice
 * status column. Confirmed live (2026-09-16): Zoho shows 35,181 Paid and
 * 2,931 Overdue invoices; our reports showed 4,680 and 2,238 respectively,
 * because ~684k of 700k invoice_items rows sat under the fake 'completed'
 * bucket instead of their real status.
 *
 * Deliberately cheap to fix: Zoho's invoice LIST endpoint already includes
 * `status` per document — no per-document detail fetch needed (unlike the
 * line-item backfill, which needed detail calls specifically for
 * line_items). A full sweep is ~206 pages (one call each) for ~41k
 * documents, comfortably inside a single hourly API budget.
 *
 * Paginates via a fixed page number (not a resumable watermark cursor like
 * SyncZohoTransactionTypeJob) since this only needs to run once to
 * completion — there's no ongoing incremental state to preserve. Re-running
 * it later just re-sweeps everything, which is fine (cheap, idempotent).
 */
class RefreshInvoiceStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const PER_PAGE = 200;

    private const THROTTLE_RETRY_DELAY_MINUTES = 10;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly int $page = 1,
        public readonly int $updatedSoFar = 0,
    ) {}

    public function handle(ZohoTransactionClient $client, ZohoApiThrottle $throttle): void
    {
        if ($throttle->tooManyAttempts($this->zohoConnection)) {
            self::dispatch($this->zohoConnection, $this->page, $this->updatedSoFar)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        $zohoConfig = ZohoTransactionMapper::zohoConfigFor('invoices');

        $throttle->hit($this->zohoConnection);
        $result = $client->fetchPage($this->zohoConnection, $zohoConfig['endpoint'], $zohoConfig['response_key'], $this->page);
        $documents = $result['documents'];
        $hasMore = $result['has_more'];

        // Grouped by status so each distinct status on this page is one
        // UPDATE ... WHERE document_number IN (...) instead of 200
        // individual updates.
        $numbersByStatus = [];
        foreach ($documents as $doc) {
            $number = $doc[$zohoConfig['number_field']] ?? null;
            $status = $doc[$zohoConfig['status_field']] ?? null;

            if ($number === null || $status === null) {
                continue;
            }

            $numbersByStatus[strtolower($status)][] = $number;
        }

        $updated = $this->updatedSoFar;
        foreach ($numbersByStatus as $status => $numbers) {
            $updated += DB::table('invoice_items')
                ->whereIn('document_number', $numbers)
                ->where('status', '!=', $status)
                ->update(['status' => $status, 'updated_at' => now()]);
        }

        if ($hasMore) {
            self::dispatch($this->zohoConnection, $this->page + 1, $updated);

            return;
        }

        Cache::put('invoice_status_refresh_last_completed', now()->toDateTimeString(), now()->addDays(30));
        Log::info("RefreshInvoiceStatusJob complete. Rows corrected: {$updated}");
    }
}
