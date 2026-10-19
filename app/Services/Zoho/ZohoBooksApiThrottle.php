<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Separate budget for Zoho BOOKS API calls (www.zohoapis.<tld>/books/v3/...)
 * — a completely independent quota from ZohoApiThrottle's Inventory budget.
 * Confirmed live 2026-10-15: Zoho's own X-Rate-Limit-* response headers
 * showed Inventory and Books tracking two unrelated counters at the same
 * moment (Inventory 10,000/day, Books 24,000/day; calling one never moved
 * the other's remaining count). Deliberately its own class/cache key, not a
 * second connection-id-keyed bucket bolted onto ZohoApiThrottle, so neither
 * budget can ever accidentally share or clobber the other's counter.
 *
 * Raised 200 → 250/hour (2026-10-15), then 250 → 400/hour (2026-10-17,
 * explicit user instruction) to give the sales Books lane (just wired into
 * the hourly cycle alongside invoices) more headroom, relieving the shared
 * Inventory-budget contention confirmed live that day. The "7 units per
 * call" cost warning this docblock used to carry was re-checked live the
 * same day against this org's real X-Rate-Limit-Remaining header on both a
 * /books/v3/salesorders list call and back-to-back calls — measured exactly
 * 1 unit per call, matching Inventory's own 1:1 accounting, not 7. At
 * 400/hour sustained 24h = 9,600/day, comfortably under Books' real
 * 24,000/day cap even if detail-fetch calls (not separately measured) cost
 * somewhat more than list calls. Re-verify the same way before raising
 * further — same spirit as ZohoApiThrottle's own history of starting
 * conservative and only being raised after real, observed headroom (see
 * that class's docblock for the 300→500→150→500 history).
 */
class ZohoBooksApiThrottle
{
    public const LIMIT_PER_HOUR = 400;

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
        return 'zoho-books-api-calls:'.$connection->id;
    }
}
