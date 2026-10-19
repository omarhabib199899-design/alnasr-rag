<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\ItemOwner;
use App\Models\ZohoConnection;
use App\Services\Inventory\InventoryRowsPersister;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoInventoryClient;
use App\Services\Zoho\ZohoItemMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 */
class SyncZohoInventoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Raised from 20 (the pre-2026-10-14 figure, sized around the HTTP
    // latency of a per-item detail call) now that each item is pure array
    // work against an already-fetched page plus an in-memory stock lookup —
    // no per-item network round-trip left to bound against the host's
    // CPU/time kill limit.
    private const ITEMS_PER_RUN = 500;

    private const THROTTLE_RETRY_DELAY_MINUTES = 10;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly ?int $importId = null,
        public readonly int $page = 1,
        public readonly int $index = 0,
    ) {}

    public function handle(
        ZohoInventoryClient $inventoryClient,
        ZohoItemMapper $itemMapper,
        InventoryRowsPersister $persister,
        ZohoApiThrottle $throttle,
    ): void {
        $import = $this->importId
            ? Import::findOrFail($this->importId)
            : Import::create([
                'user_id' => $this->zohoConnection->user_id,
                'type' => 'inventory',
                'source' => 'zoho',
                'original_filename' => 'Zoho sync — '.now()->toDateTimeString(),
                'stored_path' => null,
                'source_signature' => 'zoho:'.$this->zohoConnection->id,
                'status' => 'processing',
                'row_count' => 0,
            ]);

        if ($throttle->tooManyAttempts($this->zohoConnection)) {
            $import->touch();
            self::dispatch($this->zohoConnection, $import->id, $this->page, $this->index)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        // Resolved by a SEPARATE chained job (ResolveInventoryStockMapJob —
        // see its own docblock for why), never inline here: polling Zoho
        // Analytics needs up to several minutes across several poll
        // attempts, comfortably past this host's queue worker's 50-second
        // max-time ceiling if done with a blocking sleep() inside this
        // job's single execution (confirmed live 2026-10-14 — the import
        // just stopped being touched at all, no failed_jobs entry, nothing
        // to retry). Hand off and return immediately; once the map is
        // ready, ResolveInventoryStockMapJob redispatches this exact job at
        // this exact page/index to resume.
        $stockMapCacheKey = "zoho_inventory_stock_map:{$import->id}";
        if (! Cache::has($stockMapCacheKey)) {
            $import->touch();
            ResolveInventoryStockMapJob::dispatch($this->zohoConnection, $import->id, $this->page, $this->index);

            return;
        }

        $page = $this->page;
        $index = $this->index;
        $itemsThisRun = 0;
        $isComplete = false;
        $now = now();

        try {
            $stockByItemId = Cache::get($stockMapCacheKey, []);

            $throttle->hit($this->zohoConnection);
            $result = $inventoryClient->fetchItemsPage($this->zohoConnection, $page);
            $pageItems = $result['items'];
            $hasMore = $result['has_more'];

            $itemRows = [];

            // No per-item REST call left (stock now comes from the bulk
            // Analytics map above, everything else was already on this page
            // response) — the per-item throttle check this loop used to
            // need is gone with it; only the one fetchItemsPage call above
            // still spends real REST budget, already covered by the
            // top-of-method throttle check.
            while ($index < count($pageItems) && $itemsThisRun < self::ITEMS_PER_RUN) {
                $summary = $pageItems[$index];
                $itemId = (string) ($summary['item_id'] ?? '');

                if ($itemId !== '') {
                    $rows = $itemMapper->mapToRows($summary, $import->id, $now, $stockByItemId[$itemId] ?? 0);
                    array_push($itemRows, ...array_filter($rows, fn (array $row) => $row['sku'] !== '' && $row['name'] !== ''));

                    // Owner (Purchaser) — cf_owner is already flattened onto
                    // the LIST response (confirmed in ZohoItemMapper's own
                    // docblock), so this no longer needs the detail call
                    // either; same live-within-a-cycle reasoning as before
                    // (2026-09-27), now within minutes instead of a day.
                    $owner = $itemMapper->extractOwner($summary);
                    if ($owner !== null) {
                        ItemOwner::query()->updateOrCreate(
                            ['zoho_item_id' => $itemId],
                            [
                                'sku' => trim((string) ($summary['sku'] ?? '')) ?: null,
                                'item_name' => trim((string) ($summary['name'] ?? '')) ?: null,
                                'owner' => $owner,
                                'updated_at' => $now,
                            ],
                        );
                    }
                }

                $index++;
                $itemsThisRun++;
            }

            if (! empty($itemRows)) {
                $persister->insertChunk($import, $itemRows);
            }

            if ($index >= count($pageItems)) {
                if ($hasMore) {
                    $page++;
                    $index = 0;
                } else {
                    $isComplete = true;
                }
            }
        } catch (Throwable $e) {
            Log::warning("SyncZohoInventoryJob failed on page {$page} index {$index}, retrying in ".self::THROTTLE_RETRY_DELAY_MINUTES.'m: '.$e->getMessage());
            $import->touch();
            self::dispatch($this->zohoConnection, $import->id, $page, $index)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        if (! $isComplete) {
            $import->touch();
            self::dispatch($this->zohoConnection, $import->id, $page, $index);

            return;
        }

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
                'error_message' => 'Zoho inventory sync returned 0 items across the whole run — refusing to delete the previous snapshot.',
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

        $import->update(['status' => 'completed', 'error_message' => null]);
    }
}
