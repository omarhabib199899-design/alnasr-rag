<?php

namespace App\Jobs;

use App\Models\InventoryItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compares our recorded price (extra.rsp) against SFDA's official regulated
 * price (looked up via extra.sfda_reg_no) for one batch of items at a time
 * and re-queues itself for the next batch — same reason and pattern as
 * SyncZohoInventoryJob/SyncZohoTransactionTypeJob: running this as one long
 * blocking artisan command got SIGKILLed by the host mid-run (8097/12296
 * items, 2026-09-14), and every result was lost since the old command only
 * wrote to sfda_price_checks after the entire loop finished.
 *
 * sfda_price_checks is a standing snapshot, so the first batch (afterId 0)
 * truncates it; every batch after that writes via upsert rather than plain
 * insert, since two inventory-sync generations can briefly contain the same
 * SKU at once (the old generation's rows aren't deleted until the new one
 * finishes every page), which would otherwise violate the sku unique key.
 *
 * A cache flag guards the whole chain (set at afterId 0, cleared on the
 * final empty batch) because a manual `reports:check-sfda-prices` run and
 * the daily 04:00 schedule collided in production (2026-09-14): both
 * dispatched their own afterId-0 job, the second truncated the table out
 * from under the first chain's progress, and the count visibly jumped up
 * then reset to zero. If the flag is already set, a new afterId-0 job just
 * no-ops instead of starting a second competing chain. Deliberately a plain
 * Cache flag, not Laravel's ownable Cache::lock() (the original design,
 * reverted 2026-10-06) — an owned lock's release token only ever lived in a
 * LOCAL variable inside handle(), never persisted back onto the job object,
 * so a failed()-triggered retry (added the same day — see below) had no way
 * to carry the real owner forward and would have silently failed to release
 * it. Nothing here needs per-owner enforcement, just "is a chain already
 * running" — nothing was ever gained by the extra complexity.
 *
 * Paginated by "smallest id still unprocessed" (afterId, a WHERE id > ?
 * cursor), not by row position (OFFSET/skip) — switched 2026-10-08 after a
 * live run silently stopped at ~466 of 3671 eligible items (13%) with the
 * lock cleared as if it had finished normally: the eligible item count
 * genuinely changed mid-run (3218 at the start of the day, 3671 by the
 * time this was caught), because Zoho's hourly inventory sync keeps
 * attempting its own run in the background (and, that day, failing
 * part-way through on an unrelated broken OAuth token) — an OFFSET-based
 * query's "row N of the current result set" has no fixed meaning once rows
 * are added or removed elsewhere in that same ordering while the chain is
 * mid-flight, so a batch can land on a now-empty page well short of the
 * real end. A WHERE id > ? cursor has no such failure mode — every id
 * already issued stays issued regardless of what else gets inserted or
 * deleted around it, so the chain can only ever run short by skipping rows
 * that were deleted out from under it (an acceptable, much narrower risk:
 * those rows no longer exist to check), never by mistaking "fewer rows
 * than expected right now" for "done".
 */
class SfdaPriceCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    // Raised 5 -> 15 (2026-10-06) now that the batch's requests run
    // concurrently via Http::pool instead of one at a time — worst-case wall
    // time per batch is bounded by the slowest single request (the 8s
    // per-request timeout below) plus overhead, not BATCH_SIZE x 8s like it
    // was under the old sequential foreach, so this stays well under the 60s
    // job timeout regardless of batch size. The earlier 10 -> 5 cut
    // (2026-10-06, same day) was the fix for a *sequential*-era timeout bug
    // (10 x 8s = 80s > 60s) that no longer applies now that requests don't
    // sum.
    private const BATCH_SIZE = 15;

    // Caps retries of the SAME batch on a transient failure (the safety net
    // below) so one batch that's unrecoverably stuck (not just transiently
    // slow) can't wedge the whole daily chain forever — after this many
    // attempts it's abandoned and the chain moves on to the next batch
    // rather than the whole run silently never finishing again, the way it
    // did for 10 days straight before this fix.
    private const MAX_RETRIES_PER_BATCH = 5;

    private const LOCK_KEY = 'sfda-price-check-lock';

    // Both added 2026-10-09 after SFDA's own network blocked this server's
    // outbound IP entirely (confirmed live: even oldsfda's and
    // sfda.gov.sa's plain homepages timed out from this server while
    // working fine from elsewhere, and DNS itself still resolved — not a
    // general outage, a block specific to this IP) — traced to this job
    // having been manually re-triggered 4 times in under 7 hours that day
    // while debugging, on top of its own normal batch concurrency, which
    // together look exactly like the burst traffic an anti-abuse system is
    // built to catch. Two independent guards, not one, because they catch
    // different failure shapes: MIN_HOURS_BETWEEN_RUNS stops the run
    // *pattern* that caused it (repeated manual re-triggering) regardless
    // of whether any single run is well-behaved; the circuit breaker below
    // catches a block happening *during* an otherwise-reasonable run
    // (SFDA rate-limiting for its own reasons, independent of anything we
    // did) and stops that run from continuing to hammer an endpoint that's
    // already rejecting it.
    private const MIN_HOURS_BETWEEN_RUNS = 3;

    private const LAST_COMPLETED_AT_KEY = 'sfda-price-check-last-completed-at';

    // A batch where EVERY request failed (not just one item's SFDA lookup
    // being empty/unrecognized, which is normal and expected) is the
    // fingerprint of a block, not a legitimate per-item outcome — 3 of
    // those IN A ROW is treated as "this server is being rejected right
    // now", not "SFDA is just having a slow moment" (a single bad batch,
    // or two with a good one between them, stays well within the existing
    // per-batch retry/abandon safety net above).
    private const MAX_CONSECUTIVE_BATCH_FAILURES = 3;

    private const CONSECUTIVE_FAILURES_KEY = 'sfda-price-check-consecutive-batch-failures';

    private const COOLDOWN_KEY = 'sfda-price-check-cooldown-until';

    // Long enough that a real anti-abuse block has genuinely had time to
    // lift before the next attempt (confirmed live 2026-10-09: still fully
    // blocked 3+ hours after the triggering burst) — short enough that a
    // human doesn't have to remember to manually clear this the next day.
    private const COOLDOWN_HOURS = 3;

    public function __construct(
        public readonly int $afterId = 0,
        public readonly ?string $checkedAt = null,
        public readonly int $retryCount = 0,
    ) {}

    /**
     * Laravel calls this once this job's own $tries (1) is exhausted —
     * including for a TimeoutExceededException. That exception is delivered
     * by the worker killing the whole process via a signal handler, not by
     * throwing into the running job code, so an ordinary try/catch inside
     * handle() can never see it — failed() (reading only this job's
     * original constructor state, nothing mutated during the killed
     * execution) is the only hook that reliably fires for it. This is
     * exactly the failure mode that silently killed this chain for 10 days
     * straight (2026-09-26 to 2026-10-06) with nothing picking it back up —
     * tries=1 and no failed() handling meant a single slow batch just ended
     * the whole run, with no trace beyond a failed_jobs row nobody was
     * watching and a misleading "Never checked yet" on the report page
     * (fixed separately in ReportsController — it was reading the wrong
     * table for "last checked").
     *
     * Retries the SAME batch (same afterId cursor) a few times first —
     * covers an ordinary transient SFDA slowdown — and only moves on past
     * it once MAX_RETRIES_PER_BATCH is exhausted, so a batch that's
     * genuinely stuck (not just slow) can't block every batch after it
     * forever.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning("SfdaPriceCheckJob [afterId={$this->afterId}, retry={$this->retryCount}] failed: ".$exception->getMessage());

        if ($this->retryCount < self::MAX_RETRIES_PER_BATCH) {
            self::dispatch($this->afterId, $this->checkedAt, $this->retryCount + 1)
                ->delay(now()->addSeconds(15));

            return;
        }

        Log::warning("SfdaPriceCheckJob [afterId={$this->afterId}] abandoning this batch after ".self::MAX_RETRIES_PER_BATCH.' retries — moving on to the next batch so the rest of the run still completes.');

        // Re-derives the SAME batch this attempt was given (a cheap local
        // DB query, not the part that was failing) just to find where it
        // ends, so the cursor advances past exactly the items being
        // abandoned — not a guessed BATCH_SIZE jump, which could either
        // repeat already-abandoned items or skip ones never attempted if
        // the eligible set shifted since this batch was first dispatched.
        $skippedMaxId = $this->nextBatchQuery()->max('id');
        self::dispatch($skippedMaxId ?? $this->afterId, $this->checkedAt);
    }

    public function handle(): void
    {
        $checkedAt = $this->checkedAt ? Carbon::parse($this->checkedAt) : now();

        // Checked on EVERY batch, not just offset 0 — if the cooldown gets
        // set mid-chain (by the circuit breaker below, during this very
        // run), every batch already queued behind it needs to bail out
        // too, not just the next fresh run.
        $cooldownUntil = Cache::get(self::COOLDOWN_KEY);
        if ($cooldownUntil !== null) {
            Log::warning("SfdaPriceCheckJob: in cooldown until {$cooldownUntil} (SFDA appeared to be blocking this server) — skipping rather than risk prolonging it.");
            Cache::forget(self::LOCK_KEY);

            return;
        }

        // Only a genuinely fresh start (never a retry of afterId 0 — that
        // reuses the chain this same run already owns) acquires the flag
        // and truncates.
        if ($this->afterId === 0 && $this->retryCount === 0) {
            if (Cache::has(self::LOCK_KEY)) {
                // A chain is already running — don't start a second one
                // that would truncate the table out from under it.
                return;
            }

            // Refuses a fresh full run that would start too soon after the
            // last one genuinely finished — this is what actually stops
            // the "just re-run it again to test the fix" pattern that
            // caused the 2026-10-09 block, independent of how careful any
            // single run's own request rate is. A scheduled daily run is
            // ~24h apart and never trips this; a manual re-trigger inside
            // the same debugging session does, on purpose.
            $lastCompletedAt = Cache::get(self::LAST_COMPLETED_AT_KEY);
            if ($lastCompletedAt !== null) {
                $hoursSince = Carbon::parse($lastCompletedAt)->diffInHours(now());
                if ($hoursSince < self::MIN_HOURS_BETWEEN_RUNS) {
                    Log::warning("SfdaPriceCheckJob: last full run completed {$hoursSince}h ago (minimum ".self::MIN_HOURS_BETWEEN_RUNS."h between runs) — skipping this trigger. Clear the '".self::LAST_COMPLETED_AT_KEY."' cache key to force an earlier run if genuinely needed.");

                    return;
                }
            }

            Cache::put(self::LOCK_KEY, true, 3600);
            DB::table('sfda_price_checks')->truncate();
            // Same standing-snapshot reasoning as sfda_price_checks above —
            // rebuilt fresh each full run so an item fixed in Zoho since the
            // last run (reg_no corrected) doesn't stay listed here forever.
            DB::table('sfda_reg_no_issues')->truncate();
        }

        $items = $this->nextBatchQuery()->get();

        if ($items->isEmpty()) {
            Cache::forget(self::LOCK_KEY);
            Cache::forget(self::CONSECUTIVE_FAILURES_KEY);
            // Recorded on every genuine completion (reached the real end of
            // the eligible set) — MIN_HOURS_BETWEEN_RUNS above reads this,
            // not set on an abandoned/cooldown-interrupted run, so one of
            // those doesn't block the NEXT attempt from starting sooner.
            Cache::put(self::LAST_COMPLETED_AT_KEY, now()->toDateTimeString(), now()->addDays(2));

            return;
        }

        // Fields tracked for change detection beyond price alone — added
        // 2026-09-15 per an explicit ask to also catch things like a
        // storage-condition change (e.g. a drug newly requiring cold-chain
        // storage), not just a price mismatch. lastModificationTime isn't
        // itself compared (SFDA may touch a record for reasons outside
        // these fields), it's just carried along on the snapshot for
        // reference — the real change detection is per-field, below.
        $trackedFields = ['price', 'storage_conditions', 'legal_status', 'marketing_status', 'authorization_status'];

        $differences = [];
        $changes = [];
        $snapshots = [];
        $regNoIssues = [];
        $ambiguousRegNos = $this->ambiguousRegNos();

        // Fired concurrently via Http::pool rather than one-at-a-time —
        // confirmed live 2026-10-06 that sequential requests (each taking
        // 4-8s just waiting on SFDA's own response time) were the entire
        // bottleneck, not SFDA being down: a direct curl test against the
        // same endpoint succeeded on 4 of 5 tries in the 4-7s range while
        // the sequential job sat on ~10 items/min. Pooling the batch's
        // requests means wall-clock time per batch is bounded by the
        // slowest single request, not their sum.
        $itemsByKey = [];
        foreach ($items as $item) {
            $extra = $item->extra ?? [];
            $regNo = $extra['sfda_reg_no'] ?? null;

            if ($regNo === null || $regNo === '') {
                continue;
            }

            $itemsByKey[(string) $item->id] = ['item' => $item, 'regNo' => $regNo];
        }

        $responses = empty($itemsByKey) ? [] : Http::pool(fn ($pool) => collect($itemsByKey)
            ->map(fn ($entry, $key) => $pool->as($key)->asForm()->timeout(8)->post('https://oldsfda.sfda.gov.sa/GetDrugs.php', [
                'TradeName' => '',
                'scientificName' => '',
                'Agent' => '',
                'ManufacturerName' => '',
                'RegNo' => $entry['regNo'],
                'page' => 1,
            ]))
            ->all());

        // Circuit breaker — see MAX_CONSECUTIVE_BATCH_FAILURES's docblock.
        // Checked before processing any individual item: a batch where
        // every single request failed is itself the signal, regardless of
        // what the per-item loop below would otherwise make of it.
        if (! empty($itemsByKey)) {
            $batchTotalFailure = collect($responses)->every(
                fn ($r) => $r instanceof Throwable || ! $r || ! $r->successful() || $r->json('code') != 200
            );

            if ($batchTotalFailure) {
                $consecutiveFailures = (int) Cache::get(self::CONSECUTIVE_FAILURES_KEY, 0) + 1;
                Cache::put(self::CONSECUTIVE_FAILURES_KEY, $consecutiveFailures, 3600);

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_BATCH_FAILURES) {
                    Cache::put(self::COOLDOWN_KEY, now()->addHours(self::COOLDOWN_HOURS)->toDateTimeString(), now()->addHours(self::COOLDOWN_HOURS));
                    Cache::forget(self::CONSECUTIVE_FAILURES_KEY);
                    Cache::forget(self::LOCK_KEY);
                    Log::warning("SfdaPriceCheckJob: {$consecutiveFailures} consecutive fully-failed batches [afterId={$this->afterId}] — SFDA looks like it's blocking this server. Stopping the whole chain and cooling down for ".self::COOLDOWN_HOURS.'h instead of continuing to hammer it.');

                    return;
                }
            } else {
                Cache::forget(self::CONSECUTIVE_FAILURES_KEY);
            }
        }

        foreach ($itemsByKey as $key => $entry) {
            $item = $entry['item'];
            $regNo = $entry['regNo'];
            $extra = $item->extra ?? [];
            $ourPriceRaw = $extra['rsp'] ?? null;
            $ourPrice = is_numeric($ourPriceRaw) ? (float) $ourPriceRaw : 0.0;

            try {
                $response = $responses[$key] ?? null;

                if ($response instanceof Throwable) {
                    throw $response;
                }

                if ($response && $response->successful() && $response->json('code') == 200) {
                    $results = $response->json('data.result.results') ?? [];
                } elseif ($response) {
                    $results = null;
                    Log::warning("SfdaPriceCheckJob non-200 response [sku={$item->sku}, regNo={$regNo}]: HTTP {$response->status()}, body=".substr($response->body(), 0, 300));
                } else {
                    $results = null;
                    Log::warning("SfdaPriceCheckJob missing pool response [sku={$item->sku}, regNo={$regNo}]");
                }

                if ($results !== null) {
                    // A genuinely empty result (SFDA's search doesn't
                    // recognize some legacy dash-formatted reg numbers, e.g.
                    // "1-5210-18") means "unverifiable", not "official price
                    // is 0" — so it's skipped rather than flagged. The org's
                    // existing Zoho Deluge script treats no-result as price 0
                    // and flags it as a mismatch, which inflates its count
                    // (34 vs our 25, confirmed live 2026-09-14: the 9-item
                    // gap was entirely these unverifiable legacy reg numbers,
                    // not real price differences) — that's misleading, not
                    // more thorough, so deliberately not replicated here.
                    if (! empty($results)) {
                        $drug = $results[0];
                        $sfdaTradeName = $drug['tradeName'] ?? '';

                        // A reg_no that resolves to a DIFFERENT drug entirely
                        // (not a price mismatch, not a unit mix-up — the
                        // reg_no itself is wrong on this item in Zoho)
                        // produces nonsense price "differences", some of
                        // them the single largest numbers on the whole page
                        // — confirmed live 2026-10-07: EBETAXEL's SFDA NO
                        // (28-355-02, confirmed on the live Zoho item record
                        // itself, not a sync error) resolves to "SEDACORON
                        // 200MG TAB", an unrelated cardiac drug — a -1,737.50
                        // "mismatch" that was really just two different
                        // medicines. Recorded separately (sfda_reg_no_issues)
                        // instead of as a price difference, same reasoning as
                        // skipping an empty result below — flagging it
                        // honestly for someone to fix the reg_no in Zoho, not
                        // inventing a misleading price signal from it.
                        if (! $this->itemNamesLikelyMatch($item->name, $sfdaTradeName)) {
                            $regNoIssues[] = [
                                'sku' => $item->sku,
                                'item_name' => $item->name,
                                'sfda_reg_no' => $regNo,
                                'sfda_trade_name' => $sfdaTradeName,
                                'detected_at' => $checkedAt,
                                'created_at' => $checkedAt,
                                'updated_at' => $checkedAt,
                            ];

                            continue;
                        }

                        // SFDA registers the SAME reg_no for multiple pack
                        // sizes of one formulation (confirmed 2026-10-08,
                        // manager report: MOXAL SUSP 100ML and 200ML share
                        // one SFDA registration, since it's "one drug,
                        // different package sizes" to SFDA) — so a reg_no
                        // lookup can legitimately return a DIFFERENT size's
                        // record than the item we're checking, with a name
                        // that still passes itemNamesLikelyMatch (letters-
                        // only matching strips the "100"/"200" that's the
                        // only real difference). Comparing our price against
                        // the wrong size's official price would invent a
                        // false mismatch, same failure mode as the wrong-
                        // reg_no case above — skipped as unverifiable
                        // instead, same reasoning as an empty SFDA result.
                        $ourSize = $this->extractPackSize($item->name);
                        $sfdaSize = $this->extractPackSize($sfdaTradeName);
                        if ($ourSize !== null && $sfdaSize !== null && $ourSize !== $sfdaSize) {
                            continue;
                        }

                        // SFDA's own record sometimes omits any pack-size
                        // token from its trade name entirely — confirmed
                        // live 2026-10-08: reg_no 0501268852 (MOXAL) returns
                        // tradeName "MOXAL SUSPENSION", so $sfdaSize above
                        // is null and the check right before this one never
                        // fires, even though this exact reg_no is the one
                        // the manager flagged as covering two different
                        // pack sizes (100ML and 200ML). When SFDA's own
                        // record can't say which size its price is for, the
                        // only other signal is OUR side: if our own
                        // inventory independently shows this reg_no spread
                        // across more than one distinct size (precomputed
                        // in ambiguousRegNos(), below), there's no way to
                        // know which of them this one ambiguous price
                        // belongs to — skipped as unverifiable, same
                        // reasoning as an empty SFDA result, rather than
                        // comparing every size against it and inventing a
                        // false mismatch for whichever ones don't happen to
                        // match.
                        if ($sfdaSize === null && $ourSize !== null && isset($ambiguousRegNos[$regNo])) {
                            continue;
                        }

                        $officialPriceRaw = $drug['price'] ?? null;
                        $officialPriceRaw = is_numeric($officialPriceRaw) ? (float) $officialPriceRaw : 0.0;

                        // Checks BOTH interpretations of SFDA's price — as a
                        // whole-package price (direct) and as a per-piece
                        // price needing ×packageSize to compare against our
                        // own box price — and keeps whichever lands closer
                        // to our own rsp, rather than assuming one
                        // direction. Neither assumption holds universally:
                        // confirmed live 2026-10-07, ZYVOX/PARACETOL/etc.
                        // needed SFDA's price DIVIDED by packageSize to
                        // match ours, while BRUKINSA's SFDA price already
                        // equalled ours directly (dividing it invented a
                        // false mismatch) — extra.unit is "box" for both
                        // groups, so nothing in our own data distinguishes
                        // them either. Per direct instruction (user,
                        // 2026-10-07): check both cases. A genuine mismatch
                        // (real price change, not a unit mix-up) is still
                        // caught either way, since neither interpretation
                        // will land close to our price for one.
                        $packageSize = $drug['packageSize'] ?? 1;
                        $packageSize = is_numeric($packageSize) && $packageSize > 0 ? (float) $packageSize : 1.0;
                        $officialPriceAsPackage = $officialPriceRaw;
                        $officialPriceAsUnit = $officialPriceRaw / $packageSize;

                        // A third interpretation: our own box contains a
                        // different (larger) unit count than SFDA's
                        // registered packageSize — confirmed live 2026-10-08,
                        // manager report: GLOFEL 20MG, SFDA registers
                        // packageSize=2 at price=32 (16/tablet), but our box
                        // is "GLOFEL 20 MG 4 Tab" (4 tablets) at rsp=64,
                        // which is exactly 2 registered packages' worth
                        // (16 x 4 = 64) — neither AsPackage (32) nor AsUnit
                        // (16) above is within 0.01 of that, so without this
                        // it was flagged as a false -32.00 "mismatch" when
                        // the real per-tablet price matches exactly. Scales
                        // SFDA's per-base-unit price by OUR box's own tablet/
                        // capsule/etc. count, parsed straight from our name,
                        // rather than guessing — null (not parseable) means
                        // this candidate is simply skipped, same as any name
                        // that doesn't disclose a count.
                        $ourUnitCount = $this->extractUnitCount($item->name);
                        $officialPriceAsOurCount = $ourUnitCount !== null
                            ? $officialPriceAsUnit * $ourUnitCount
                            : null;

                        $candidates = array_filter(
                            [$officialPriceAsPackage, $officialPriceAsUnit, $officialPriceAsOurCount],
                            fn ($v) => $v !== null
                        );
                        $officialPrice = collect($candidates)->sortBy(fn ($v) => abs($v - $ourPrice))->first();

                        // Threshold raised 0.001 -> 0.01 (confirmed live
                        // 2026-10-06, same class of bug as the "price"
                        // field's string-vs-numeric fix below): our own
                        // rsp is stored to 2 decimals, SFDA's API
                        // sometimes returns a 3rd decimal digit (e.g.
                        // 3225.299 vs our 3225.30 — a true difference of
                        // exactly 0.001, not noise), and ordinary float
                        // arithmetic on two decimals that aren't exactly
                        // representable in binary can nudge that right up
                        // against (or past) a boundary this tight. 0.01
                        // (one full halala) comfortably absorbs that
                        // without hiding a real price change, which in
                        // practice is never that small.
                        if (abs($officialPrice - $ourPrice) > 0.01) {
                            $differences[] = [
                                'sku' => $item->sku,
                                'item_name' => $item->name,
                                'sfda_reg_no' => $regNo,
                                'our_price' => $ourPrice,
                                'official_price' => $officialPrice,
                                'price_difference' => $officialPrice - $ourPrice,
                                'checked_at' => $checkedAt,
                                'created_at' => $checkedAt,
                                'updated_at' => $checkedAt,
                            ];
                        }

                        $newSnapshot = [
                            'price' => (string) $officialPrice,
                            'storage_conditions' => $drug['storageConditions']['nameEn'] ?? null,
                            'legal_status' => $drug['legalStatus']['nameEn'] ?? null,
                            'marketing_status' => $drug['marketingStatus']['nameEn'] ?? null,
                            'authorization_status' => $drug['authorizationStatus']['nameEn'] ?? null,
                        ];

                        $oldSnapshot = DB::table('sfda_item_snapshots')->where('sku', $item->sku)->first();

                        // No prior snapshot means this is the first time
                        // we've ever seen this item — nothing to compare
                        // against yet, so it just establishes the baseline
                        // rather than reporting every field as "changed".
                        if ($oldSnapshot !== null) {
                            foreach ($trackedFields as $field) {
                                $oldValue = $oldSnapshot->{$field};
                                $newValue = $newSnapshot[$field];

                                // Numeric fields compared as numbers, not
                                // strings — the 'price' column round-trips
                                // through a DECIMAL(12,2) DB column ("8.60"),
                                // while a freshly-computed float casts to
                                // "8.6" ((string) drops the trailing zero),
                                // so a plain string compare flagged every
                                // unchanged price as "changed" (confirmed
                                // live 2026-09-16: 142 false "changes", all
                                // formatting noise, zero real ones). That
                                // fix wasn't tight enough on its own —
                                // confirmed live 2026-10-06: 58 more
                                // "changes" logged, every one a price
                                // moving by exactly 0.001 (e.g. 3225.30 ->
                                // 3225.299), the same 2-vs-3-decimal source
                                // mismatch the price-check threshold above
                                // has, just caught here as a false
                                // "change" instead of a false "mismatch".
                                // Same 0.01 threshold, same reasoning.
                                $changed = $field === 'price'
                                    ? abs((float) $oldValue - (float) $newValue) > 0.01
                                    : (string) $oldValue !== (string) $newValue;

                                if ($newValue !== null && $changed) {
                                    $changes[] = [
                                        'sku' => $item->sku,
                                        'item_name' => $item->name,
                                        'field_changed' => $field,
                                        'old_value' => $oldValue,
                                        'new_value' => $newValue,
                                        'detected_at' => $checkedAt,
                                        'created_at' => $checkedAt,
                                        'updated_at' => $checkedAt,
                                    ];
                                }
                            }
                        }

                        $snapshots[] = array_merge($newSnapshot, [
                            'sku' => $item->sku,
                            'item_name' => $item->name,
                            'sfda_reg_no' => $regNo,
                            'last_modification_time' => isset($drug['lastModificationTime'])
                                ? Carbon::parse($drug['lastModificationTime'])
                                : null,
                            'checked_at' => $checkedAt,
                            'created_at' => $checkedAt,
                            'updated_at' => $checkedAt,
                        ]);
                    }
                }
            } catch (Throwable $e) {
                // Skip — the next scheduled run will retry this item naturally.
                Log::warning("SfdaPriceCheckJob item failed [sku={$item->sku}, regNo={$regNo}]: ".get_class($e).': '.$e->getMessage());
            }
        }

        if (! empty($differences)) {
            DB::table('sfda_price_checks')->upsert(
                $differences,
                ['sku'],
                ['item_name', 'sfda_reg_no', 'our_price', 'official_price', 'price_difference', 'checked_at', 'updated_at']
            );
        }

        if (! empty($changes)) {
            // Appended, never truncated — sfda_changes is a running log
            // ("what changed and when"), not a standing snapshot like
            // sfda_price_checks.
            foreach (array_chunk($changes, 500) as $chunk) {
                DB::table('sfda_changes')->insert($chunk);
            }
        }

        if (! empty($snapshots)) {
            DB::table('sfda_item_snapshots')->upsert(
                $snapshots,
                ['sku'],
                ['item_name', 'sfda_reg_no', 'last_modification_time', 'price', 'storage_conditions', 'legal_status', 'marketing_status', 'authorization_status', 'checked_at', 'updated_at']
            );
        }

        if (! empty($regNoIssues)) {
            DB::table('sfda_reg_no_issues')->upsert(
                $regNoIssues,
                ['sku'],
                ['item_name', 'sfda_reg_no', 'sfda_trade_name', 'detected_at', 'updated_at']
            );
        }

        self::dispatch($items->max('id'), $checkedAt->toDateTimeString());
    }

    /**
     * The next up-to-BATCH_SIZE eligible items strictly after $this->afterId
     * — a plain DB query, cheap and reliable regardless of what's happening
     * to SFDA's endpoint, so both handle() and failed()'s abandon-this-batch
     * path can safely call it fresh rather than trying to remember/guess
     * which items a given cursor value was supposed to cover.
     */
    private function nextBatchQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return InventoryItem::query()
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(extra, '$.sfda_reg_no')) = 'STRING'")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.sfda_reg_no')) != ''")
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->take(self::BATCH_SIZE);
    }

    /**
     * Letters-only prefix match, not a word-split match — revised same day
     * (2026-10-07) after the original word-split version produced real
     * false positives flagged manually before sending the list to the
     * manager: "E-MOX 250 SUSP." vs SFDA's "E-MOX 250MG DRY MIX." is the
     * SAME drug, but splitting on "-" turned our name's first word into
     * "E" (1 letter, filtered out) and "MOX" (3 letters, below the old 4-
     * letter floor), so neither ever got compared — same failure mode hit
     * GALVUSMET/"GALVUS MET" (no space on our side vs a space on SFDA's)
     * and POSTINOR2/"POSTINOR-2" (digit glued to the word on our side,
     * hyphenated on SFDA's). Stripping everything but letters from BOTH
     * names first and checking prefix containment survives all three: "E-
     * MOX"->"EMOX" is found inside "EMOXMGDRYMIX", "GALVUSMET" is found
     * inside "GALVUSMET" (space removed), "POSTINOR2"->"POSTINOR" is found
     * inside "POSTINOR2"->"POSTINOR" (hyphen removed). Still a sanity
     * floor, not a precise match — generic-name-vs-brand-name pairs that
     * share zero letters in sequence (e.g. "Normal Saline" vs "Sodium
     * Chloride", the same product under its common vs. chemical name)
     * won't be caught by any substring approach and need a human glance,
     * which is exactly why this writes to a reviewable list
     * (sfda_reg_no_issues) instead of silently acting on it.
     */
    private function itemNamesLikelyMatch(string $ourName, string $sfdaTradeName): bool
    {
        $ourClean = strtoupper(preg_replace('/[^A-Za-z]/', '', $ourName));
        $sfdaClean = strtoupper(preg_replace('/[^A-Za-z]/', '', $sfdaTradeName));

        if ($ourClean === '') {
            return true;
        }

        for ($len = min(8, strlen($ourClean)); $len >= 4; $len--) {
            if (str_contains($sfdaClean, substr($ourClean, 0, $len))) {
                return true;
            }
        }

        // Our name's cleaned length is under 4 letters (rare, e.g. a
        // numbers-heavy name) — fall back to requiring the whole thing.
        return strlen($ourClean) < 4 && str_contains($sfdaClean, $ourClean);
    }

    /**
     * First "<number><unit>" pack-size token in a drug name (e.g. "100ML",
     * "5GM", "200MG"), normalized (spaces removed, uppercased) so "100 ML"
     * and "100ML" compare equal. Returns null when no such token is found —
     * callers only act on this when BOTH names have one, so a name that
     * simply doesn't mention a volume/weight never triggers a false skip.
     */
    private function extractPackSize(string $name): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(ML|MG|GM|MCG|IU|L)\b/i', $name, $m)) {
            return strtoupper($m[1].$m[2]);
        }

        return null;
    }

    /**
     * How many discrete dosage units (tablets, capsules, vials, ampoules,
     * sachets, suppositories, pre-filled syringes/pens) OUR box's name says
     * it contains (e.g. "4" from "GLOFEL 20 MG 4 Tab", "30" from
     * "...250MG 30TAB", "2" from "...2pre-filled syringe") — distinct from
     * extractPackSize() above, which pulls a volume/weight token (ML/MG/...)
     * rather than a discrete count. The optional "pre-filled"/"prefilled"
     * infix (added 2026-10-08, PRALUENT) covers the common pattern of a
     * count glued straight to that phrase rather than to the unit word
     * itself — confirmed live: SFDA registers PRALUENT by the single pen
     * (packageSize=1, price=936.55), so our box of "2pre-filled syringe"
     * needs its own count recognized to scale that per-pen price up to a
     * genuinely comparable two-pen price (1873.10) instead of leaving the
     * comparison silently mismatched in UNITS, not just amount — showing
     * our box's 1755.00 next to a single pen's 936.55 looks like an
     * 818.45 gap when the real, still-worth-reviewing gap (after scaling
     * by our own 2-pen count) is a much smaller 118.10. Only matches a
     * handful of common unit words; a name that doesn't disclose a count
     * this way returns null rather than guessing, same as extractPackSize().
     */
    private function extractUnitCount(string $name): ?int
    {
        if (preg_match('/(\d+)\s*(?:PRE[- ]?FILLED\s+)?(TAB|CAP|VIAL|AMP|SACHET|SUPP|SYRINGE|PEN)S?\b/i', $name, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * reg_nos where OUR OWN inventory already contains more than one
     * distinct pack size (e.g. MOXAL 100ML and 200ML both carry reg_no
     * 0501268852) — this is the fallback signal for the pack-size check
     * above, for the cases where SFDA's own trade name doesn't mention a
     * size at all so there's nothing on their side to compare against.
     * Computed once per run and cached (same TTL/lifetime as LOCK_KEY)
     * rather than re-querying the whole table on every batch.
     */
    private function ambiguousRegNos(): array
    {
        return Cache::remember('sfda-ambiguous-reg-nos', 3600, function () {
            $sizesByRegNo = [];

            InventoryItem::query()
                ->whereRaw("JSON_TYPE(JSON_EXTRACT(extra, '$.sfda_reg_no')) = 'STRING'")
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.sfda_reg_no')) != ''")
                ->get(['name', 'extra'])
                ->each(function ($item) use (&$sizesByRegNo) {
                    $regNo = $item->extra['sfda_reg_no'] ?? null;
                    $size = $this->extractPackSize($item->name);

                    if ($regNo === null || $regNo === '' || $size === null) {
                        return;
                    }

                    $sizesByRegNo[$regNo][$size] = true;
                });

            return array_filter($sizesByRegNo, fn ($sizes) => count($sizes) > 1);
        });
    }
}
