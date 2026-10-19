<?php

namespace App\Jobs;

use App\Models\PurchaseRecommendation;
use App\Models\ZohoConnection;
use App\Services\Reports\PurchaseRecommendationPusher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a bounded batch of Purchase Recommendation rows to Zoho, then
 * re-queues itself with whatever SKUs are left — same self-chaining
 * pattern as SyncZohoTransactionTypeJob, and for the same reason: a single
 * long-running foreground push of thousands of rows got silently killed by
 * this host's CPU/time limit. Keeping each execution to a small batch,
 * running via the existing every-minute queue tick, avoids that.
 */
class PushPurchaseRecommendationsJob implements ShouldQueue
{
    use Queueable;

    // Was 1 — confirmed live (2026-09-12) this makes the chain fragile in
    // exactly the way this host punishes: when the worker process gets
    // SIGKILLed mid-batch (routine here, zero warning), the in-flight job's
    // attempt count reaches 1 without ever finishing handle() — which never
    // reached the self-dispatch of the next batch — so with tries=1 that one
    // interruption permanently kills the entire remaining chain, requiring a
    // manual `queue:retry` every single time. Raised so an interrupted
    // attempt gets retried automatically instead.
    public int $tries = 5;

    private const BATCH_SIZE = 25;

    /**
     * @param  array<int, string>  $skus  remaining SKUs to push, in order
     */
    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly array $skus,
    ) {}

    public function handle(PurchaseRecommendationPusher $pusher): void
    {
        $batch = array_slice($this->skus, 0, self::BATCH_SIZE);
        $remaining = array_slice($this->skus, self::BATCH_SIZE);

        $rows = PurchaseRecommendation::whereIn('sku', $batch)->get()->keyBy('sku');

        foreach ($batch as $sku) {
            $recommendation = $rows->get($sku);
            if ($recommendation === null) {
                continue;
            }

            try {
                $pusher->push($this->zohoConnection, $recommendation->toArray());
            } catch (Throwable $e) {
                // One SKU failing (e.g. a transient Zoho error) shouldn't
                // stop the rest of the batch or the chain — log it and move
                // on. It'll naturally get retried on the next scheduled
                // --push run since its hash still won't match.
                Log::warning("PushPurchaseRecommendationsJob: failed to push SKU {$sku}: ".$e->getMessage());
            }

            usleep(300_000);
        }

        if (! empty($remaining)) {
            self::dispatch($this->zohoConnection, $remaining);
        }
    }
}
