<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\InvoiceDocument;
use App\Models\ZohoConnection;
use App\Models\ZohoSyncCursor;
use App\Services\Inventory\TransactionImportProcessor;
use App\Services\Inventory\TransactionRowsPersister;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoInventoryClient;
use App\Services\Zoho\ZohoTransactionClient;
use App\Services\Zoho\ZohoTransactionMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs one transaction type (sales/purchase_orders/returns/invoices) a
 * small batch of documents at a time, persisting each batch immediately and
 * re-queueing itself to continue.
 *
 * Two things this design works around, both confirmed against this org's
 * live API rather than assumed:
 *
 * 1. Zoho's list endpoint (e.g. GET /purchaseorders) does NOT include
 *    line_items — only the per-document detail endpoint does. So a full
 *    sync means one extra API call per document, not just per page of 200.
 *    fetchPage() gets the list (cheap, summary fields only — enough to
 *    check the watermark and skip old documents without a wasted detail
 *    call), then fetchDetail() is called per document that's actually new.
 *
 * 2. The original one-shot-per-type design got silently killed mid-run in
 *    production on a large first-time backfill (no exception, no failed
 *    status — the process just died), almost certainly this shared host's
 *    CPU/wall-time limit (CloudLinux LVE). Keeping each execution to a
 *    handful of documents keeps it comfortably under that, and since every
 *    document is persisted (upserted) before moving on, a kill between
 *    executions loses at most the one in-flight document, never previously
 *    completed work.
 *
 * Resume position lives on ZohoSyncCursor (next_page/next_index), not on
 * this job, so it survives across however many chained dispatches a type's
 * full backfill takes.
 */
class SyncZohoTransactionTypeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Documents detail-fetched per execution. Conservative on purpose —
     * each one is a full HTTP round-trip on top of the list-page fetch, and
     * this is what's standing between a large backfill and getting killed
     * again. Lowered from 15 to 8 after confirming live (2026-09-14/15)
     * that a slow-Zoho run (near its own rate limit) could take long enough
     * per document that 15 x 2 HTTP calls exceeded the queue worker's
     * default 60s job timeout — a hard process kill that bypasses this
     * job's own try/catch entirely, so no retry ever got dispatched and the
     * chain died silently (3 TimeoutExceededException failures the same day
     * this was found).
     */
    private const MAX_DOCUMENTS_PER_RUN = 8;

    /**
     * Subtracted from the newest document's timestamp before committing it
     * as the watermark, so the next pass always re-checks a small
     * overlapping window instead of trusting the exact boundary. Confirmed
     * live (2026-09-23): a burst of sales orders bulk-touched by Zoho within
     * the same ~15-minute window shifted which page some of them landed on
     * between this chain's separate HTTP calls (each job run is a separate
     * request, sometimes minutes apart due to throttling) — page/index
     * cursors only ever move forward, so a document that got pushed to a
     * page already passed was silently skipped forever (QT-78368 and
     * several others never synced despite having a last_modified_time
     * older than the committed watermark). Re-processing a handful of
     * already-synced documents each pass is cheap (upsert is idempotent);
     * missing one silently is not.
     */
    private const WATERMARK_SAFETY_BUFFER_MINUTES = 15;

    /**
     * @param  array<string, string>  $skuByItemId  item_id => sku, built from the inventory sync
     * @param  ?string  $cursorKey  identity used for the ZohoSyncCursor row and the Import's own
     *     `type` column — defaults to $type (existing behavior, one cursor per real Zoho type).
     *     Passed as "{$type}_recent" by ZohoSyncService to run a SECOND, independent cursor per
     *     type: a small/fast "what changed since I last checked" chain that isn't blocked by
     *     alreadyRunning() while the main full-historical chain (cursor key = $type) is still
     *     mid-backfill. $type itself always drives the real Zoho endpoint/table config — never
     *     touched by this — so both chains fetch/persist against the exact same real data.
     */
    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly string $type,
        public readonly array $skuByItemId,
        public readonly ?int $importId = null,
        public readonly ?string $cursorKey = null,
    ) {}

    /**
     * Minutes to wait before retrying once the hourly call budget is hit —
     * fixed rather than computed against the exact window reset, since
     * that's close enough and self-corrects (still throttled next time
     * just delays again) without needing to reach into RateLimiter internals.
     */
    private const THROTTLE_RETRY_DELAY_MINUTES = 10;

    public function handle(
        ZohoTransactionClient $transactionClient,
        ZohoTransactionMapper $transactionMapper,
        TransactionRowsPersister $persister,
        ZohoApiThrottle $throttle,
        ZohoInventoryClient $inventoryClient,
    ): void {
        $transactionConfig = TransactionImportProcessor::CONFIG[$this->type];
        $zohoConfig = ZohoTransactionMapper::zohoConfigFor($this->type);
        // Resolved into a LOCAL variable once, then used everywhere below —
        // never re-read $this->cursorKey directly after this line. Confirmed
        // live (2026-09-28): a job dispatched by the OLD 4-argument
        // self::dispatch(...) call (queued before this cursorKey parameter
        // existed) deserializes into a readonly typed property with no
        // value at all, and PHP throws "must not be accessed before
        // initialization" on any direct read of it — but NOT here, where
        // it's behind ??, which is what let this line resolve safely to
        // $this->type as a fallback while three already-queued old-format
        // jobs crashed on this file's own direct-access redispatch lines
        // before this fix.
        $cursorKey = $this->cursorKey ?? $this->type;

        // Resolved (and cached on the connection) rather than trusting
        // zoho_connection_id as the cursor's identity — see the cursor
        // lookup below for why.
        $organizationId = $inventoryClient->resolveOrganizationId($this->zohoConnection);

        // Loaded/created before the throttle check (and touched below on
        // every path that re-dispatches) specifically so ZohoSyncService's
        // alreadyRunning() staleness check stays accurate. It previously sat
        // after the throttle check, so a throttled early-return never
        // touched the Import's updated_at — if the org-wide quota stayed
        // exhausted for more than alreadyRunning()'s 20-minute window (easy,
        // since other automations share the same daily cap), the next
        // hourly tick saw a "stale" processing Import, assumed this type's
        // chain had died, and started a second one from scratch — orphaning
        // the first Import at "processing" forever. Confirmed live
        // (2026-09-14/15): 204 Import rows stuck this way back to 2026-09-12,
        // plus 73 duplicate jobs dispatched in the same second once several
        // types went stale simultaneously.
        $import = $this->importId
            ? Import::findOrFail($this->importId)
            : Import::create([
                'user_id' => $this->zohoConnection->user_id,
                'type' => $cursorKey,
                'source' => 'zoho',
                'original_filename' => 'Zoho sync — '.now()->toDateTimeString(),
                'stored_path' => null,
                'source_signature' => 'zoho:'.$this->zohoConnection->id.':'.$cursorKey,
                'status' => 'processing',
                'row_count' => 0,
            ]);

        if ($throttle->tooManyAttempts($this->zohoConnection)) {
            $import->touch();
            self::dispatch($this->zohoConnection, $this->type, $this->skuByItemId, $import->id, $cursorKey)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        // Keyed by the Zoho ORGANIZATION, not the connection row, on
        // purpose. It used to key on zoho_connection_id — but that row gets
        // replaced (a new id) every time the connection is disconnected and
        // reconnected, even for the exact same Zoho org, which silently
        // reset next_page/next_index/last_modified_watermark to blank every
        // time. Confirmed live (2026-09-15): a reconnect that morning threw
        // away a fully-caught-up watermark and forced hours of re-crawling
        // through the entire multi-year history from scratch, burning deep
        // into the org's shared daily 10,000-call budget hours before staff
        // start work. Organization id is stable across reconnects (it's
        // Zoho's own identifier for the company, not our OAuth session), so
        // this cursor now survives any future reconnect for the same org.
        $cursor = ZohoSyncCursor::firstOrCreate(
            ['zoho_organization_id' => $organizationId, 'type' => $cursorKey],
            ['zoho_connection_id' => $this->zohoConnection->id, 'next_page' => 1, 'next_index' => 0],
        );

        // firstOrCreate(), on the insert path, only reflects the attributes
        // explicitly passed to it — it doesn't refetch the row to pick up
        // MySQL's own column defaults (next_page=1, next_index=0). Without
        // these ?? fallbacks a brand-new cursor has next_index === null,
        // and null used as an array key below silently becomes "" — not 0.
        $page = max(1, $cursor->next_page ?? 1);
        $index = $cursor->next_index ?? 0;
        $pendingWatermark = $cursor->pending_watermark;
        $documentsThisRun = 0;
        $reachedWatermark = false;
        $budgetExhausted = false;
        $isComplete = false;

        // No watermark yet means this cursor has never finished one full
        // pass — either a brand-new connection, or (confirmed live
        // 2026-10-14, invoices) a years-long backfill that's still mid-flight
        // because the shared hourly API budget spreads thin across 9
        // concurrent cursors. A pass this slow is exactly the case
        // fetchPage()'s docblock warns about: sorting by last_modified_time
        // lets every real edit made during the crawl re-sort that document
        // ahead of the crawl's current position, silently skipping it
        // forever — confirmed live the same day (CUS-1517/invoice 86399: a
        // real Zoho edit from Oct 8 still missing from our copy on Oct 14).
        // created_time is immutable, so sorting by it instead makes a
        // page's membership permanent regardless of how long the backfill
        // takes — the fix applies automatically to every cursor still in
        // this state (any type, not just invoices), and stops applying the
        // moment a cursor's first full pass completes and sets its own
        // watermark below.
        $isInitialBackfill = $cursor->last_modified_watermark === null;
        $sortColumn = $isInitialBackfill ? 'created_time' : 'last_modified_time';
        $sortOrder = $isInitialBackfill ? 'A' : 'D';

        // Steady-state passes additionally pass the watermark as a real
        // server-side filter (see fetchPage()'s docblock) — confirmed live
        // 2026-10-15 after invoices 87167/87195/87129 were found silently
        // skipped despite the hourly "_recent" cursor running several times
        // since: Zoho actually honors `last_modified_time` as a genuine
        // "modified at or after" filter, so a document can no longer fall
        // out of the matching set just because it got re-sorted mid-crawl —
        // only the sort-only approach (no filter) is prone to that.
        $modifiedSinceFilter = $isInitialBackfill ? null : Carbon::parse($cursor->last_modified_watermark)->utc()->format('Y-m-d\TH:i:sO');

        try {
            $throttle->hit($this->zohoConnection);
            $result = $transactionClient->fetchPage($this->zohoConnection, $zohoConfig['endpoint'], $zohoConfig['response_key'], $page, $sortColumn, $sortOrder, $modifiedSinceFilter);
            $pageDocuments = $result['documents'];
            $hasMorePages = $result['has_more'];

            $now = now();
            $itemRows = [];

            while ($index < count($pageDocuments) && $documentsThisRun < self::MAX_DOCUMENTS_PER_RUN) {
                if ($throttle->tooManyAttempts($this->zohoConnection)) {
                    // Budget ran out mid-batch — stop here rather than push
                    // through the rest of this batch over the cap.
                    $budgetExhausted = true;
                    break;
                }

                $summary = $pageDocuments[$index];

                // Under the old last_modified_time-DESC sort, the very
                // first document seen (page 1, index 0) was always the
                // single most-recently-modified one, so capturing it once
                // was enough. Under created_time-ASC (backfill mode), the
                // documents arrive oldest-first, so their last_modified_time
                // values are in no particular order — the watermark this
                // pass should commit at the end is the MAXIMUM
                // last_modified_time seen across every document processed,
                // not whichever one happened to be seen first.
                if ($isInitialBackfill) {
                    $modifiedTime = $summary['last_modified_time'] ?? null;
                    if ($modifiedTime !== null && ($pendingWatermark === null || Carbon::parse($modifiedTime)->gt(Carbon::parse($pendingWatermark)))) {
                        $pendingWatermark = $modifiedTime;
                    }
                } elseif ($pendingWatermark === null) {
                    $pendingWatermark = $summary['last_modified_time'] ?? null;
                }

                if ($cursor->last_modified_watermark !== null) {
                    $modifiedTime = $summary['last_modified_time'] ?? null;
                    if ($modifiedTime !== null && Carbon::parse($modifiedTime)->lessThanOrEqualTo($cursor->last_modified_watermark)) {
                        // Newest-first order guarantees every remaining document
                        // on this and later pages is at least this old too.
                        $reachedWatermark = true;
                        break;
                    }
                }

                $documentId = (string) ($summary[$zohoConfig['id_field']] ?? '');
                if ($documentId !== '') {
                    $throttle->hit($this->zohoConnection);
                    $detail = $transactionClient->fetchDetail($this->zohoConnection, $zohoConfig['endpoint'], $zohoConfig['detail_response_key'], $documentId);
                    $rows = $transactionMapper->mapToRows($detail, $this->type, $import->id, $this->skuByItemId, $now);
                    array_push($itemRows, ...$rows);

                    // Same detail response also carries document-level
                    // fields (status/balance/due_date/...) that
                    // invoice_documents needs for the customer page's
                    // balance/overdue calculations — no extra API call.
                    // See ZohoTransactionMapper::mapToInvoiceDocument()'s
                    // docblock for why this only updates a subset of columns.
                    if ($this->type === 'invoices') {
                        $invoiceDocument = $transactionMapper->mapToInvoiceDocument($detail);
                        if ($invoiceDocument !== null) {
                            InvoiceDocument::query()->updateOrCreate(
                                ['zoho_invoice_id' => $invoiceDocument['zoho_invoice_id']],
                                $invoiceDocument,
                            );
                        }
                    }
                }

                $index++;
                $documentsThisRun++;
            }

            if (! empty($itemRows)) {
                $persister->persistIncremental($itemRows, $transactionConfig['table'], $transactionConfig['date_column']);
                $import->increment('row_count', count($itemRows));
            }

            if ($reachedWatermark) {
                $isComplete = true;
            } elseif ($index >= count($pageDocuments)) {
                if ($hasMorePages) {
                    $page++;
                    $index = 0;
                } else {
                    $isComplete = true;
                }
            }
        } catch (Throwable $e) {
            // tries=1 on purpose (see class docblock), but that means any
            // transient failure here — a Zoho rate-limit 429, a network
            // blip — used to permanently kill this self-dispatching chain:
            // the job went straight to failed_jobs and nothing ever
            // re-triggered it, so the backfill silently stalled until a
            // human noticed and manually started a fresh sync. Confirmed in
            // production on a multi-day invoices backfill. Retry from the
            // same page/index instead, same as the throttle-exhausted path
            // below — the cursor is untouched so nothing is lost.
            Log::warning("SyncZohoTransactionTypeJob [{$cursorKey}] failed on page {$page} index {$index}, retrying in ".self::THROTTLE_RETRY_DELAY_MINUTES.'m: '.$e->getMessage());
            $import->touch();
            self::dispatch($this->zohoConnection, $this->type, $this->skuByItemId, $import->id, $cursorKey)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        if (! $isComplete) {
            $cursor->update(['next_page' => $page, 'next_index' => $index, 'pending_watermark' => $pendingWatermark]);
            $import->touch();
            $next = self::dispatch($this->zohoConnection, $this->type, $this->skuByItemId, $import->id, $cursorKey);
            if ($budgetExhausted) {
                $next->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));
            }

            return;
        }

        // Steady-state passes anchor the new watermark to when THIS pass
        // started ($import->created_at, fixed across however many chained
        // executions the pass took), not the newest last_modified_time this
        // pass happened to observe. With the server-side filter above, any
        // document touched DURING the pass still has last_modified_time
        // after the pass's start, so anchoring there — not at whatever page
        // that document ended up shuffled onto — guarantees the very next
        // pass's filter still catches it. The old max-seen-value anchor
        // couldn't offer that guarantee: it reflected whichever document
        // happened to be seen first, not a fixed point the whole pass is
        // provably complete up to. Initial backfills (created_time-sorted,
        // no server-side filter available since the sort column differs)
        // keep the max-seen-value anchor — unaffected by this change.
        $newWatermark = $isInitialBackfill
            ? ($pendingWatermark !== null ? Carbon::parse($pendingWatermark)->utc()->subMinutes(self::WATERMARK_SAFETY_BUFFER_MINUTES) : $cursor->last_modified_watermark)
            : Carbon::parse($import->created_at)->utc()->subMinutes(self::WATERMARK_SAFETY_BUFFER_MINUTES);

        $cursor->update([
            'next_page' => 1,
            'next_index' => 0,
            'pending_watermark' => null,
            // ->utc() is load-bearing, not cosmetic: confirmed live
            // (2026-09-28) that Zoho's last_modified_time comes back with a
            // +0300 offset (Saudi time), and Eloquent's 'datetime' cast
            // writes whatever timezone the Carbon instance is CURRENTLY in
            // — it does not itself convert to the app's UTC timezone before
            // formatting for storage. Without this, the naive DATETIME
            // column ends up holding the raw "16:09" wall-clock value as if
            // it were already UTC — 3 hours ahead of reality — silently
            // treating anything modified in roughly the last 3 hours as
            // "already covered" and skipping it forever. Caught via a real
            // Purchase Order (PO-29732, modified 16:09:41+0300 =
            // 13:09:41 UTC) that a live API call could see but three
            // consecutive hourly "_recent" passes never picked up.
            'last_modified_watermark' => $newWatermark,
        ]);
        $import->update(['status' => 'completed', 'error_message' => null]);
    }
}
