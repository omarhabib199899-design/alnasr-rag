<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Handles the OAuth2 authorization-code dance with Zoho's accounts server.
 * Each customer authorizes their own Zoho Inventory account — this client
 * never sees more than one customer's tokens at a time.
 */
class ZohoOAuthClient
{
    /**
     * There is no "organizations" scope module — the /organizations endpoint
     * falls under settings.READ. Comma-separated, per Zoho's OAuth scope
     * convention. Adding a scope here requires existing connections to
     * disconnect/reconnect — a token can't gain scope retroactively.
     */
    // Empirically confirmed: writing to a custom module record lives at
    // /inventory/v1/{module_name}, not /books/v3/ (probing the live API
    // showed the Books path 404s while the Inventory path recognizes the
    // resource but rejects for scope — 401, not 404). So the write scope
    // needed is ZohoInventory.custommodules.ALL, mirroring the ZohoBooks
    // equivalent by Zoho's naming convention. Kept the two ZohoBooks scopes
    // too since they're already granted and harmless if unused.
    // ZohoAnalytics.* added 2026-09-12: the org has a pre-existing Zoho
    // Analytics sync of Books/Invoice/Subscription data that's already
    // complete (682k+ invoice line item rows, full history, includes
    // pricing) — a far better source than continuing to backfill invoices/
    // sale orders one API-limited document at a time. data.READ to query
    // table rows, metadata.READ to list workspaces/tables/columns first.
    // ZohoInventory.contacts.READ added 2026-09-22: the org's Zoho Analytics
    // "Customers" export has no address, contact persons, owner, currency,
    // tax treatment, or approval-status fields at all (confirmed live —
    // searched every related Analytics table, found none) — the live
    // Contacts API's per-contact detail response is the only place left to
    // get them, per an explicit ask for "every detail" on the customer page.
    //
    // ZohoInventory.customerpayments.READ added 2026-10-14: per the
    // manager's explicit direction — the Payments Received screen was
    // pulling 'customer_payment' rows out of ar_ledger_entries (itself
    // sourced from the Zoho Analytics "AR Ledger Master" view), which he's
    // now ruled out entirely ("الليدجر اللي على زوهو أناليتكس... ما
    // نستعملوش في حاجة أصلاً لأنه ممكن يتغير في أي لحظة" — don't use that
    // Analytics ledger for anything, it can change under us). Confirmed
    // live the same day that the existing scope list has no permission for
    // GET /inventory/v1/customerpayments at all (401, code 57) — this is
    // what unlocks a real sync straight from Zoho's own Customer Payments
    // module instead.
    //
    // ZohoBooks.invoices.READ added 2026-10-15: purely diagnostic — the
    // shared ZohoApiThrottle (our own self-imposed 500/hr guess) has been
    // the bottleneck on several heavy-sync days, and Inventory calls
    // confirmed live the SAME DAY that Zoho returns its own real quota
    // directly via X-Rate-Limit-* response headers (10,000/day, not
    // 500/hr — our number was always a guess layered on top of this).
    // Whether Zoho Books draws from that SAME per-organization pool or a
    // genuinely separate one was unverified (a GET to /books/v3/... 401'd
    // outright — no Books read scope existed at all, so the question
    // couldn't even be tested). This scope exists only to make one
    // authorized Books call and compare its X-Rate-Limit-Remaining against
    // Inventory's in the same moment — not yet used by any sync job.
    //
    // ZohoInventory.creditnotes.READ added 2026-10-15: per the manager's
    // explicit ask (voice note, relayed live) — the Credit Notes page had
    // no "view line items" drill-down the way Invoices does, because its
    // existing data (ar_ledger_entries, entity_type='creditnote') is a
    // document-level Analytics figure with no line-item detail at all.
    // Confirmed live GET /inventory/v1/creditnotes 401'd (code 57) before
    // this scope — the real endpoint exists, we just weren't authorized
    // for it. Mirrors ZohoInventory.salesreturns.READ/invoices.READ exactly.
    private const SCOPE = 'ZohoInventory.items.READ,ZohoInventory.settings.READ,ZohoInventory.salesorders.READ,ZohoInventory.purchaseorders.READ,ZohoInventory.salesreturns.READ,ZohoInventory.invoices.READ,ZohoInventory.contacts.READ,ZohoInventory.customerpayments.READ,ZohoInventory.custommodules.ALL,ZohoInventory.creditnotes.READ,ZohoBooks.custommodules.ALL,ZohoBooks.settings.CREATE,ZohoBooks.invoices.READ,ZohoBooks.salesorders.READ,ZohoAnalytics.data.READ,ZohoAnalytics.metadata.READ';

    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'scope' => self::SCOPE,
            'client_id' => config('zoho.client_id'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'redirect_uri' => route('zoho.callback'),
            'prompt' => 'consent',
            'state' => $state,
        ];

        return config('zoho.accounts_base_url').'/oauth/v2/auth?'.http_build_query($params);
    }

    /**
     * @return array{access_token: string, refresh_token: string, api_domain: string, expires_in: int}
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->timeout(30)->retry(3, 1000)->post(config('zoho.accounts_base_url').'/oauth/v2/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('zoho.client_id'),
            'client_secret' => config('zoho.client_secret'),
            'redirect_uri' => route('zoho.callback'),
            'code' => $code,
        ]);

        $data = $response->json();

        if (! $response->successful() || empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new RuntimeException('Zoho token exchange failed: '.($data['error'] ?? $response->body()));
        }

        return $data;
    }

    /**
     * Mints a fresh access token from the stored refresh token and saves it
     * on the connection. No-op if the current token still has headroom.
     */
    public function refreshIfNeeded(ZohoConnection $connection): void
    {
        if ($connection->access_token_expires_at && $connection->access_token_expires_at->isAfter(now()->addMinutes(5))) {
            return;
        }

        $response = Http::asForm()->timeout(30)->retry(3, 1000)->post(config('zoho.accounts_base_url').'/oauth/v2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('zoho.client_id'),
            'client_secret' => config('zoho.client_secret'),
            'refresh_token' => $connection->refresh_token,
        ]);

        $data = $response->json();

        if (! $response->successful() || empty($data['access_token'])) {
            throw new RuntimeException('Zoho token refresh failed: '.($data['error'] ?? $response->body()));
        }

        $connection->update([
            'access_token' => $data['access_token'],
            'access_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            // Zoho may rotate the refresh token on each use (issue a new one
            // and invalidate the old) — if the response includes one, it
            // MUST be persisted, or every refresh after the first silently
            // dooms the next one to fail with an already-consumed token.
            'refresh_token' => $data['refresh_token'] ?? $connection->refresh_token,
        ]);
    }
}
