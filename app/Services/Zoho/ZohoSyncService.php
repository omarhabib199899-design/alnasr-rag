<?php

namespace App\Services\Zoho;

use App\Jobs\SyncZohoInventoryJob;
use App\Jobs\SyncZohoTransactionTypeJob;
use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\ZohoConnection;

/**
 * Orchestrates one sync run across all five Zoho-backed types: inventory,
 * sales orders, purchase orders, returns, invoices.
 *
 * All five now run as chunked, self-dispatching jobs (SyncZohoInventoryJob,
 * SyncZohoTransactionTypeJob) instead of inline here. Inventory used to run
 * synchronously in this method — judged "a single paginated fetch, small
 * enough to be safe" — until the queue worker process running it got
 * killed mid-fetch by this host's CPU/time limit in production, same
 * failure mode as the original transaction-sync design. There's no longer
 * a reason to treat inventory differently, so this method just dispatches
 * all five and returns; it doesn't wait on or aggregate their results,
 * since they complete asynchronously over however many queue ticks a full
 * sync takes. Check each type's own Import row for status.
 */
class ZohoSyncService
{
    // Emptied 2026-10-19: purchase_order_items, sale_items, invoice_items and return_items are now fed from Zoho
    // Analytics by zoho:feed-legacy-tables — no Inventory API calls for any transaction type any more.
    private const TRANSACTION_TYPES = [];

    public function sync(ZohoConnection $connection): void
    {
        if (! $this->alreadyRunning($connection, 'inventory')) {
            SyncZohoInventoryJob::dispatch($connection);
        }

        // item_id => sku, read from whatever inventory sync has already
        // persisted (this run's or an earlier completed one) rather than a
        // fresh in-memory fetch — decouples the transaction types from
        // inventory's own (now async, possibly still in-progress) sync.
        $skuByItemId = $this->skuByItemIdFromDatabase();

        foreach (self::TRANSACTION_TYPES as $type) {
            // A type's chunked job chain can easily run longer than an hour
            // on a large first-time backfill (deliberately throttled/paced
            // — see SyncZohoTransactionTypeJob). Without this check, the
            // next hourly tick would start a SECOND chain for the same
            // type while the first is still mid-flight, and both would
            // read/write the same ZohoSyncCursor row concurrently —
            // confusing at best, lost progress at worst.
            if (! $this->alreadyRunning($connection, $type)) {
                SyncZohoTransactionTypeJob::dispatch($connection, $type, $skuByItemId);
            }

            // A SECOND, independent cursor per type ("{$type}_recent"),
            // added 2026-09-28 after a first-time backfill on a fresh
            // Zoho connection (no prior watermark) took well over a day —
            // during that whole stretch the main chain above never reaches
            // "now" (it's still working through last year), and the
            // alreadyRunning() guard blocks a second dispatch of the SAME
            // cursor type, so genuinely new/edited documents had no way to
            // reach us until the full historical crawl finished. This
            // cursor is seeded to "now" the moment it's first created (see
            // the recent-cursor migration/seed script) — so the FIRST time
            // it runs it finds nothing to do, and every run after that is a
            // small "what changed since my last check" pass, same
            // newest-first watermark logic, just against its own cursor
            // row — completely unblocked by however far behind the main
            // backfill still is.
            $recentCursorKey = "{$type}_recent";
            if (! $this->alreadyRunning($connection, $recentCursorKey)) {
                SyncZohoTransactionTypeJob::dispatch($connection, $type, $skuByItemId, null, $recentCursorKey);
            }
        }

        // Added 2026-09-22, now that ZohoInventory.contacts.READ is live and
        // ZohoContactSyncJob's double-JSON-encoding bug is fixed — a new or
        // edited Zoho contact now reaches customers.live_contact_data within
        // one hourly tick, same cadence as the four transaction types above.
        // Kept manually-triggerable too (reports:sync-contacts-live) for
        // testing without waiting for the schedule.
        // Stopped 2026-10-19: customers are kept in step with Zoho Analytics by zoho:feed-legacy-tables, and Payments
        // Received reads the Analytics Customer Payment table — no Books Contacts / Customer Payments API calls any more.

        // The two Books-API lanes (invoices, added 2026-10-15; sales, added
        // 2026-10-17) used to be dispatched right here on every hourly tick
        // — moved out to their own 15-minute schedule entry instead (see
        // routes/console.php's "zoho-books-sync" and syncBooksLanes()'s own
        // docblock below for the full reasoning). Confirmed live the same
        // day this call stayed here too: at the top of every hour, BOTH
        // this hourly tick AND the 15-minute one fire within seconds of
        // each other (13:00:02 and 13:00:07 on this org's live log), each
        // independently dispatching and completing its own full Books sync
        // — not harmful (idempotent upsert) but a real, avoidable waste of
        // the exact budget this split was meant to protect. Removed here
        // entirely now that the 15-minute schedule already covers every
        // case the hourly tick used to, plus three more per hour.

        $connection->update([
            'status' => 'connected',
            'last_error' => null,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * The two Books-API lanes (invoices, sales) — called hourly from sync()
     * above and, separately, every 15 minutes on its own (see
     * routes/console.php) so a lane that falls behind gets a chance to
     * close the gap several times an hour, not once. Public (not folded
     * into sync()) specifically so the 15-minute schedule entry can call
     * just this, without re-dispatching inventory/sales/purchase_orders/
     * returns/invoices's own Inventory-budget jobs four times as often for
     * no reason — those already have their own "_recent" catch-up cursor
     * and don't need tighter polling, only the Books lanes do.
     */
    public function syncBooksLanes(ZohoConnection $connection, array $skuByItemId): void
    {
        // Stopped 2026-10-19: invoice_items and sale_items come from Zoho Analytics (zoho:feed-legacy-tables), so the
        // Books-API invoice/sales lanes no longer run. Kept as a no-op so the 15-minute schedule entry stays valid.
    }

    /**
     * A "processing" Import only counts as an active chain if it's been
     * touched recently — each execution of the chunked job updates its
     * Import row (row_count increments, or the completion update), so a
     * genuinely alive chain's updated_at stays fresh every queue tick or
     * two. Without the recency check, ANY way a chain could die mid-run
     * (this host's CPU/time limit, a stalled connection, anything we
     * haven't hit yet) would leave that Import stuck at "processing"
     * forever — and since that status alone used to be enough to skip
     * dispatching a fresh chain, the type would just stay broken until
     * someone manually found and fixed it in the database. Confirmed
     * happening in production: three types sat dead for 5+ hours before
     * this was added.
     */
    /**
     * Confirmed live 2026-09-30: this 20-minute window was too tight for
     * inventory specifically. Its job self-throttles with 10-minute delays
     * (SyncZohoInventoryJob::THROTTLE_RETRY_DELAY_MINUTES) and, since the
     * per-item detail fetch was added, a full generation takes roughly a
     * day — so a stacked throttle delay plus ordinary queue latency can
     * easily leave its Import row untouched for over 20 minutes while the
     * chain is still very much alive. When that happened, the hourly
     * scheduler saw "not running" and started a second, overlapping
     * generation — and when one of the two finished, its "delete every
     * other inventory import's rows" cleanup step wiped out the other
     * generation's still-in-progress rows out from under it (that step
     * didn't check whether the "other" import was itself still
     * processing). Inventory gets a much longer window; transaction types
     * keep the original 20 minutes, which is what the docblock above this
     * method was written against.
     */
    private function alreadyRunning(ZohoConnection $connection, string $type): bool
    {
        $staleAfterMinutes = $type === 'inventory' ? 90 : 20;

        return Import::where('user_id', $connection->user_id)
            ->where('type', $type)
            ->where('source', 'zoho')
            ->where('status', 'processing')
            ->where('updated_at', '>', now()->subMinutes($staleAfterMinutes))
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    public function skuByItemIdFromDatabase(): array
    {
        return InventoryItem::query()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.zoho_item_id')) as zoho_item_id, sku")
            ->whereNotNull('extra')
            ->get()
            ->filter(fn ($row) => $row->zoho_item_id !== null)
            ->pluck('sku', 'zoho_item_id')
            ->all();
    }
}
