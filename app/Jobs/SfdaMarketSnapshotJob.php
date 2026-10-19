<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\SfdaMarketChangesDetected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily full-market SFDA snapshot — pulls EVERY registered drug (not just
 * items in our own inventory_items), via SFDA's own bulk listing page
 * (oldsfda.sfda.gov.sa/GetDrugs.php with every filter field, including
 * RegNo, left blank — confirmed live 2026-10-07 this returns a paginated
 * listing of the whole database, 459 pages x 20/page = ~9,165 items).
 * Direct request from the manager (voice note, 2026-10-07): SfdaPriceCheckJob
 * only ever compares SFDA's data against OUR OWN rsp price for items we
 * carry — this job answers a different question entirely ("what changed in
 * SFDA itself since yesterday, across the whole market"), independent of
 * whether we stock the item.
 *
 * Same self-redispatching per-batch pattern as SfdaPriceCheckJob (one long
 * blocking run risks the same SIGKILL/queue-timeout failure modes), same
 * Http::pool concurrency within a batch (confirmed live 2026-10-06 this is
 * what actually fixed SfdaPriceCheckJob's throughput — sequential
 * single-request-at-a-time was the bottleneck, not SFDA being down).
 *
 * On the final batch (no more pages), diffs today's snapshot against
 * yesterday's (sfda_market_snapshots is insert-only — every day's full
 * snapshot kept permanently, not overwritten) across price/storage/legal/
 * marketing/authorization/agent, writes every difference to
 * sfda_market_changes, and sends ONE digest database notification to every
 * user — not email/SMS, per explicit direction (manager, 2026-10-07):
 * in-app only, no external email service to set up/maintain.
 */
class SfdaMarketSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    // Pages per batch, fetched concurrently via Http::pool — wall time per
    // batch is bounded by the slowest single request, not the sum, so this
    // stays well under the 60s job timeout regardless of batch size. Kept
    // modest (8) to stay gentle on SFDA's own server across a 459-page
    // sweep.
    private const BATCH_SIZE = 8;

    // 15s, not SfdaPriceCheckJob's 8s — confirmed live 2026-10-07 this bulk
    // listing endpoint (20 full, deeply-nested drug records per page) is
    // genuinely heavier than that job's single-item lookup and routinely
    // takes ~8-9s to respond even to just one request, let alone 8
    // concurrent ones (first live run: all 8 requests in the first batch
    // timed out at 8s with 50-130KB already received — truncated by the
    // timeout, not a real failure). 15s gives real margin; 8 concurrent
    // requests at this endpoint's real response time still finish in ~9-10s
    // total (confirmed live), nowhere near the 60s job timeout either way.
    private const REQUEST_TIMEOUT_SECONDS = 15;

    private const MAX_RETRIES_PER_BATCH = 5;

    private const LOCK_KEY = 'sfda-market-snapshot-lock';

    public function __construct(
        public readonly int $page = 1,
        public readonly ?string $snapshotDate = null,
        public readonly int $retryCount = 0,
    ) {}

    /**
     * Same reasoning as SfdaPriceCheckJob::failed() — a TimeoutExceededException
     * is delivered by the worker killing the process via a signal handler, so
     * only this hook (reading the job's original constructor state) reliably
     * fires for it. Retries the same page-batch a few times first, then
     * abandons it and moves on so one stuck batch can't wedge the whole
     * day's sweep.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning("SfdaMarketSnapshotJob [page={$this->page}, retry={$this->retryCount}] failed: ".$exception->getMessage());

        if ($this->retryCount < self::MAX_RETRIES_PER_BATCH) {
            self::dispatch($this->page, $this->snapshotDate, $this->retryCount + 1)
                ->delay(now()->addSeconds(15));

            return;
        }

        Log::warning("SfdaMarketSnapshotJob [page={$this->page}] abandoning this batch after ".self::MAX_RETRIES_PER_BATCH.' retries — moving on to the next batch.');
        self::dispatch($this->page + self::BATCH_SIZE, $this->snapshotDate);
    }

    public function handle(): void
    {
        $snapshotDate = $this->snapshotDate ? Carbon::parse($this->snapshotDate) : now();

        if ($this->page === 1 && $this->retryCount === 0) {
            if (Cache::has(self::LOCK_KEY)) {
                return;
            }

            Cache::put(self::LOCK_KEY, true, 7200);
        }

        $pagesToFetch = range($this->page, $this->page + self::BATCH_SIZE - 1);

        $responses = Http::pool(fn ($pool) => collect($pagesToFetch)
            ->map(fn ($page) => $pool->as((string) $page)->asForm()->timeout(self::REQUEST_TIMEOUT_SECONDS)->post('https://oldsfda.sfda.gov.sa/GetDrugs.php', [
                'TradeName' => '',
                'scientificName' => '',
                'Agent' => '',
                'ManufacturerName' => '',
                'RegNo' => '',
                'page' => $page,
            ]))
            ->all());

        $rows = [];
        $pageCount = null;
        $anyPageHadData = false;

        foreach ($pagesToFetch as $page) {
            $response = $responses[(string) $page] ?? null;

            try {
                if ($response instanceof Throwable) {
                    throw $response;
                }

                if (! $response || ! $response->successful() || $response->json('code') != 200) {
                    Log::warning("SfdaMarketSnapshotJob non-200 response [page={$page}]: ".($response ? "HTTP {$response->status()}" : 'no response'));

                    continue;
                }

                $result = $response->json('data.result');
                $pageCount = $result['pageCount'] ?? $pageCount;
                $items = $result['results'] ?? [];

                if (! empty($items)) {
                    $anyPageHadData = true;
                }

                foreach ($items as $drug) {
                    $regNo = $drug['registerNumber'] ?? null;
                    if ($regNo === null || $regNo === '') {
                        continue;
                    }

                    $priceRaw = $drug['price'] ?? null;

                    $rows[] = [
                        'sfda_reg_no' => (string) $regNo,
                        'trade_name' => $drug['tradeName'] ?? null,
                        'price' => is_numeric($priceRaw) ? (float) $priceRaw : null,
                        'storage_conditions' => $drug['storageConditions']['nameEn'] ?? null,
                        'legal_status' => $drug['legalStatus']['nameEn'] ?? null,
                        'marketing_status' => $drug['marketingStatus']['nameEn'] ?? null,
                        'authorization_status' => $drug['authorizationStatus']['nameEn'] ?? null,
                        'agent_name' => $drug['drugAgents'][0]['agent']['nameEn'] ?? null,
                        'snapshot_date' => $snapshotDate->toDateString(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            } catch (Throwable $e) {
                Log::warning("SfdaMarketSnapshotJob page failed [page={$page}]: ".get_class($e).': '.$e->getMessage());
            }
        }

        if (! empty($rows)) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('sfda_market_snapshots')->upsert(
                    $chunk,
                    ['sfda_reg_no', 'snapshot_date'],
                    ['trade_name', 'price', 'storage_conditions', 'legal_status', 'marketing_status', 'authorization_status', 'agent_name', 'updated_at']
                );
            }
        }

        // A batch where EVERY page failed (no successful response at all)
        // is never "we've reached the end" — reaching the real end still
        // gets ordinary 200 responses (with pageCount telling us so), just
        // with no items left. Zero successful responses out of 8 concurrent
        // requests is a transient failure (SFDA's own DNS has been flaky
        // this session — confirmed live 2026-10-07, two separate runs lost
        // their first batch to this exact pattern, one of which silently
        // "finished" after writing nothing because the old logic here
        // treated total failure as done). Throwing routes it through
        // failed()'s existing retry-same-batch-then-abandon safety net
        // instead of silently declaring victory.
        if (! $anyPageHadData && $pageCount === null) {
            throw new \RuntimeException("SfdaMarketSnapshotJob: entire batch [page={$this->page}] got zero successful responses — treating as transient, not end-of-data.");
        }

        $knownLastPage = $pageCount !== null ? (int) $pageCount : null;
        $isPastEnd = $knownLastPage !== null && $this->page > $knownLastPage;

        if ($isPastEnd) {
            Cache::forget(self::LOCK_KEY);
            $this->finalizeDay($snapshotDate);

            return;
        }

        self::dispatch($this->page + self::BATCH_SIZE, $snapshotDate->toDateTimeString());
    }

    /**
     * Diffs today's snapshot against yesterday's and writes every
     * difference to sfda_market_changes, then sends one digest
     * notification. Runs once, from the final batch of the day's chain.
     */
    private function finalizeDay(Carbon $snapshotDate): void
    {
        $today = $snapshotDate->toDateString();
        $yesterday = $snapshotDate->copy()->subDay()->toDateString();

        $todayRows = DB::table('sfda_market_snapshots')->where('snapshot_date', $today)->get()->keyBy('sfda_reg_no');
        $yesterdayRows = DB::table('sfda_market_snapshots')->where('snapshot_date', $yesterday)->get()->keyBy('sfda_reg_no');

        if ($yesterdayRows->isEmpty()) {
            // First day this job has ever run (or yesterday's snapshot is
            // missing) — nothing to diff against yet, establishes the
            // baseline only.
            Log::info("SfdaMarketSnapshotJob: no snapshot for {$yesterday} to diff against — baseline only, {$todayRows->count()} items snapshotted for {$today}.");

            return;
        }

        $trackedFields = ['price', 'storage_conditions', 'legal_status', 'marketing_status', 'authorization_status', 'agent_name'];
        $changes = [];
        $priceIncreases = 0;
        $priceDecreases = 0;
        $agentChanges = 0;
        $otherFieldChanges = 0;

        foreach ($todayRows as $regNo => $row) {
            $prev = $yesterdayRows->get($regNo);
            if ($prev === null) {
                continue;
            }

            foreach ($trackedFields as $field) {
                $oldValue = $prev->{$field};
                $newValue = $row->{$field};

                $changed = $field === 'price'
                    ? abs((float) $oldValue - (float) $newValue) > 0.01
                    : (string) $oldValue !== (string) $newValue;

                if (! $changed || $newValue === null) {
                    continue;
                }

                $changes[] = [
                    'sfda_reg_no' => $regNo,
                    'trade_name' => $row->trade_name,
                    'field_changed' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'detected_date' => $today,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($field === 'price') {
                    (float) $newValue > (float) $oldValue ? $priceIncreases++ : $priceDecreases++;
                } elseif ($field === 'agent_name') {
                    $agentChanges++;
                } else {
                    $otherFieldChanges++;
                }
            }
        }

        if (! empty($changes)) {
            foreach (array_chunk($changes, 500) as $chunk) {
                DB::table('sfda_market_changes')->insert($chunk);
            }
        }

        $totalChanges = $priceIncreases + $priceDecreases + $agentChanges + $otherFieldChanges;

        Log::info("SfdaMarketSnapshotJob: {$totalChanges} change(s) detected for {$today} vs {$yesterday} ({$priceIncreases} price up, {$priceDecreases} price down, {$agentChanges} agent, {$otherFieldChanges} other).");

        if ($totalChanges > 0) {
            $notification = new SfdaMarketChangesDetected($today, $priceIncreases, $priceDecreases, $agentChanges, $otherFieldChanges);
            User::all()->each(fn (User $user) => $user->notify($notification));
        }
    }
}
