<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\ItemOwner;
use App\Models\ZohoConnection;
use App\Services\Inventory\InventoryRowsPersister;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fetches Zoho Inventory items a small batch at a time and re-queues itself
 * to continue — same reason and same pattern as SyncZohoTransactionTypeJob:
 * running this as one long blocking call got the whole queue worker process
 * killed by this host's CPU/time limit mid-fetch, in production.
 *
 * Inventory is still a full-refresh snapshot, not an incremental upsert
 * (unlike transactions — it needs to reflect deletions too): each execution
 * inserts its batch into a brand-new Import's rows, and only once every item
 * is in does the LAST execution delete the previous generation's rows and
 * mark the new one complete. An interrupted run just leaves the OLD snapshot
 * fully intact — nothing is ever deleted until the new one is proven whole.
 *
 * Per-item DETAIL fetch (added 2026-09-23, REMOVED 2026-10-14): Zoho's items
 * LIST endpoint stopped including any stock quantity field for this org, so
 * a per-item detail call (one extra HTTP round-trip EACH, ~6,400 of them)
 * was added to recover it — sharing the same ZohoApiThrottle budget every
 * transaction sync also depends on, which stretched one full generation to
 * 17-30+ hours and starved every other sync type at the same time. Confirmed
 * live the same day: a Zoho Analytics report already maintained in this
 * workspace ("INVENTORY DATA", config('zoho.analytics.views.inventory_stock'))
 * carries current stock-on-hand per item at batch/FIFO granularity, and its
 * item count matched Zoho's own live Items UI exactly (6,408). Stock now
 * comes from ONE bulk export of that view, resolved by a SEPARATE chained
 * job (ResolveInventoryStockMapJob — see its docblock for why it's not done
 * inline here) and read back from cache — every other field (name/sku/
 * price/cost/category/cf_* custom fields) was already present on the cheap
 * LIST response the whole time (confirmed against ZohoItemMapper's own
 * field list), so this is the only thing the detail call was ever still
 * needed for. A full generation now finishes in minutes, not days.
 *
 * Items from Zoho Analytics (2026-10-19): the item list itself now comes from zoho_raw_items (the Analytics
 * "Items" table, refreshed hourly by zoho:refresh) instead of the Inventory API's /items pages, so this job makes
 * no Inventory API call at all. Same fields: SKU, Item Name, Sales Price (price), Purchase Price (cost), Usage unit,
 * RSP, OWNER. Category is left empty (it was empty for every Zoho item). SFDA No. is not in the Analytics table, so
 * each item keeps the value from the previous snapshot. The whole list (~6.4k rows) is built in one run; the
 * page/index arguments are kept only so ResolveInventoryStockMapJob's redispatch still matches.
 */
class SyncZohoInventoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly ?int $importId = null,
        public readonly int $page = 1,
        public readonly int $index = 0,
    ) {}

    public function handle(InventoryRowsPersister $persister): void
    {
        $import = $this->importId
            ? Import::findOrFail($this->importId)
            : Import::create([
                'user_id' => $this->zohoConnection->user_id,
                'type' => 'inventory',
                'source' => 'zoho',
                'original_filename' => 'Zoho Analytics — '.now()->toDateTimeString(),
                'stored_path' => null,
                'source_signature' => 'zoho:'.$this->zohoConnection->id,
                'status' => 'processing',
                'row_count' => 0,
            ]);

        // Resolved by a SEPARATE chained job (ResolveInventoryStockMapJob —
        // see its own docblock for why), never inline here: polling Zoho
        // Analytics needs up to several minutes across several poll
        // attempts, comfortably past this host's queue worker's 50-second
        // max-time ceiling if done with a blocking sleep() inside this
        // job's single execution. Hand off and return immediately; once the
        // map is ready, ResolveInventoryStockMapJob redispatches this job.
        $stockMapCacheKey = "zoho_inventory_stock_map:{$import->id}";
        if (! Cache::has($stockMapCacheKey)) {
            $import->touch();
            ResolveInventoryStockMapJob::dispatch($this->zohoConnection, $import->id, $this->page, $this->index);

            return;
        }

        $stockByItemId = Cache::get($stockMapCacheKey, []);
        $now = now();

        // SFDA No. per item from the last good snapshot (not in the Analytics Items table).
        $previousIds = Import::where('type', 'inventory')->where('source', 'zoho')->where('status', 'completed')
            ->where('id', '!=', $import->id)->pluck('id');
        $sfdaByItemId = InventoryItem::whereIn('import_id', $previousIds)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.zoho_item_id')) as item_id, JSON_UNQUOTE(JSON_EXTRACT(extra, '$.sfda_reg_no')) as sfda")
            ->toBase()->get()
            ->filter(fn ($r) => $r->item_id !== null && $r->sfda !== null && $r->sfda !== '' && $r->sfda !== 'null')
            ->pluck('sfda', 'item_id')->all();

        // A run interrupted mid-way (or an older API-paged generation picked up after the deploy) may have left rows on
        // this import — start it clean so nothing is doubled.
        InventoryItem::where('import_id', $import->id)->delete();
        $import->update(['row_count' => 0]);

        $itemRows = [];
        $owners = [];
        foreach (DB::table('zoho_raw_items')->orderBy('item_id')->get() as $item) {
            $itemId = (string) $item->item_id;
            $sku = trim((string) $item->sku);
            $name = trim((string) $item->item_name);
            $owner = trim((string) $item->owner) ?: null;
            if ($itemId === '' || $sku === '' || $name === '') {
                continue;
            }
            $itemRows[] = [
                'import_id' => $import->id,
                'sku' => $sku,
                'name' => $name,
                'price' => (float) ($item->sales_price ?? 0),
                'cost' => $item->purchase_price !== null ? (float) $item->purchase_price : null,
                'category' => null,
                'stock' => (int) ($stockByItemId[$itemId] ?? 0),
                'extra' => json_encode([
                    'zoho_item_id' => $itemId,
                    'unit' => $item->usage_unit,
                    'sfda_reg_no' => $sfdaByItemId[$itemId] ?? null,
                    'rsp' => $item->rsp,
                    'owner' => $owner,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($owner !== null) {
                $owners[] = ['zoho_item_id' => $itemId, 'sku' => $sku, 'item_name' => $name, 'owner' => $owner, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($itemRows, 500) as $chunk) {
            $persister->insertChunk($import, $chunk);
        }
        // Owner (Purchaser) per item, as before — one bulk upsert instead of one query per item.
        foreach (array_chunk($owners, 500) as $chunk) {
            ItemOwner::query()->upsert($chunk, ['zoho_item_id'], ['sku', 'item_name', 'owner', 'updated_at']);
        }
        $import->refresh();

        // Confirmed live 2026-09-23: Zoho's items list endpoint silently
        // returned zero items across every page for 14 straight hourly runs
        // while this job still finished each one as "completed" — and the
        // delete-stale-snapshots step below used to run unconditionally
        // once a run finished, so each of those empty runs wiped the real
        // inventory data and left the dashboard showing 0 stock for the
        // rest of the day. Refuse to treat an empty result as a valid new
        // generation: keep the last known-good snapshot fully intact and
        // surface this as a failure instead, so it's visible (failed_jobs /
        // Import.status) rather than silently destroying data.
        if ($import->row_count === 0) {
            $import->update([
                'status' => 'failed',
                'error_message' => 'zoho_raw_items gave 0 usable items — refusing to delete the previous snapshot.',
            ]);

            return;
        }

        // Every item is in — safe now to drop the previous generation's
        // rows. Scoped to prior Zoho-sourced inventory imports that are
        // themselves already COMPLETED, not just any other inventory import
        // — confirmed live 2026-09-30, root cause of a 2-day undercounted
        // dashboard: ZohoSyncService::alreadyRunning()'s 20-minute staleness
        // window was too tight for this job's own throttle delays, so the
        // hourly scheduler occasionally started a second, overlapping
        // inventory generation. When one of the two finished, this step
        // used to delete rows from EVERY other inventory import regardless
        // of status — including the sibling generation that was still
        // actively "processing" — wiping its progress out from under it
        // while it kept incrementing its own row_count afterward (which is
        // why that counter can't be trusted; it no longer reflects reality
        // once this happens). Excluding 'processing' siblings here fixes
        // the actual bug; ZohoSyncService::alreadyRunning() was also
        // widened to make the overlap itself much less likely to begin
        // with. Excel-uploaded inventory snapshots are still never touched,
        // same as before.
        $staleImportIds = Import::where('type', 'inventory')
            ->where('source', 'zoho')
            ->where('status', 'completed')
            ->where('id', '!=', $import->id)
            ->pluck('id');

        if ($staleImportIds->isNotEmpty()) {
            // Belt-and-braces on top of the fix above: compare ACTUAL
            // persisted row counts on both sides, not the row_count
            // bookkeeping field — that field is exactly what the bug above
            // proved unreliable (it counts every row this generation ever
            // inserted, even ones later deleted out from under it by
            // another process). Refuse to replace a materially larger
            // previous snapshot with a much smaller new one.
            $newActualCount = InventoryItem::where('import_id', $import->id)->count();
            $previousGoodCount = InventoryItem::whereIn('import_id', $staleImportIds)->count();
            if ($previousGoodCount > 0 && $newActualCount < $previousGoodCount * 0.8) {
                $import->update([
                    'status' => 'failed',
                    'error_message' => "New inventory generation only captured {$newActualCount} rows vs {$previousGoodCount} in the last good snapshot — refusing to replace it.",
                ]);

                return;
            }

            InventoryItem::whereIn('import_id', $staleImportIds)->delete();
        }

        // Rows left behind by older generations that never completed (failed, or abandoned mid-way under the old API
        // paging) — readers that do not filter by import (skuPriceMap, the sku maps, SFDA checks) would otherwise see them.
        $abandonedIds = Import::where('type', 'inventory')->where('source', 'zoho')->whereIn('status', ['processing', 'failed'])
            ->where('id', '<', $import->id)->pluck('id');
        if ($abandonedIds->isNotEmpty()) {
            InventoryItem::whereIn('import_id', $abandonedIds)->delete();
        }

        $import->update(['status' => 'completed', 'error_message' => null]);
    }
}
