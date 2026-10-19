<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Caps Zoho API traffic per connection to a fixed budget per rolling hour,
 * shared across every transaction type's chunked job. Not applied to
 * inventory sync: its call volume is small and fixed (paginated by item
 * count, not per-document detail fetches like transactions now require) and
 * doesn't meaningfully compete for budget.
 *
 * Raised 300 → 500 on 2026-09-28 (explicit user decision) to give the new
 * parallel "_recent" cursors (see SyncZohoTransactionTypeJob/ZohoSyncService)
 * real headroom alongside the first-time full historical backfill, instead
 * of being starved behind it. Flagged tradeoff at the time: 500/hour
 * sustained 24h = 12,000/day, over this account's real 10,000/day Zoho cap
 * — and that cap is also shared with at least one other automation outside
 * this app (a Deluge approval workflow), which has hit it before on its
 * own. Accepted risk, not a guaranteed-safe number — if 429s return, this
 * is the first thing to turn back down.
 *
 * Temporarily lowered 500 → 150 on 2026-10-05 (explicit user request, after
 * that day's testing — full customer backfill/render sweeps plus the new
 * ReconcileCustomers — burned through the real Zoho daily quota and tripped
 * this exact limiter for real). Restored 150 → 500 the same day once that
 * one-off testing load was over (explicit user instruction).
 */
class ZohoApiThrottle
{
    public const LIMIT_PER_HOUR = 500;

    private const DECAY_SECONDS = 3600;

    public function tooManyAttempts(ZohoConnection $connection): bool
    {
        return RateLimiter::tooManyAttempts($this->key($connection), self::LIMIT_PER_HOUR);
    }

    public function hit(ZohoConnection $connection): void
    {
        RateLimiter::hit($this->key($connection), self::DECAY_SECONDS);
    }

    private function key(ZohoConnection $connection): string
    {
        return 'zoho-api-calls:'.$connection->id;
    }
}
