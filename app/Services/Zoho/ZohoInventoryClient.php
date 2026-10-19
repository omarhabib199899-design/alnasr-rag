<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Zoho Inventory's REST API for the one endpoint this
 * app needs: listing items. `api_domain` is per-connection (Zoho has
 * separate regional data centers — .com/.eu/.in/etc.) and comes back from
 * the OAuth token exchange, never assumed.
 */
class ZohoInventoryClient
{
    public function __construct(private readonly ZohoOAuthClient $oauth) {}

    /**
     * Zoho Inventory requires an organization_id on every request. A
     * connection has exactly one Zoho account authorized, so we resolve it
     * once (first organization returned) and cache it on the connection.
     */
    public function resolveOrganizationId(ZohoConnection $connection): string
    {
        if ($connection->zoho_organization_id) {
            return $connection->zoho_organization_id;
        }

        $this->oauth->refreshIfNeeded($connection);

        $response = Http::withHeaders($this->authHeaders($connection))
            ->timeout(30)->retry(3, 1000)
            ->get($connection->api_domain.'/inventory/v1/organizations');

        $organizations = $response->json('organizations') ?? [];

        if (! $response->successful() || empty($organizations)) {
            throw new RuntimeException('Could not resolve a Zoho organization for this connection: '.$response->body());
        }

        $organizationId = (string) $organizations[0]['organization_id'];
        // currency_code/currency_symbol (e.g. "SAR") ride along in this same
        // organizations response — already fetching it for organization_id,
        // so capturing these too costs nothing extra. Lets every currency
        // label in the app read this instead of a hardcoded symbol, which
        // matters the moment this system is sold to a company billing in a
        // different currency (confirmed live 2026-10-04 this field exists
        // and is populated: "currency_code":"SAR","currency_symbol":"SAR"
        // for Al Nasr's own organization).
        $connection->update([
            'zoho_organization_id' => $organizationId,
            'currency_code' => $organizations[0]['currency_code'] ?? $connection->currency_code,
            'currency_symbol' => $organizations[0]['currency_symbol'] ?? $connection->currency_symbol,
        ]);

        return $organizationId;
    }

    /**
     * @return array<int, array<string, mixed>> raw Zoho item objects
     */
    public function fetchAllItems(ZohoConnection $connection): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->resolveOrganizationId($connection);

        $items = [];
        $page = 1;
        $perPage = 200;

        do {
            $response = Http::withHeaders($this->authHeaders($connection))
                ->timeout(30)->retry(3, 1000)
                ->get($connection->api_domain.'/inventory/v1/items', [
                    'organization_id' => $organizationId,
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Zoho items fetch failed on page '.$page.': '.$response->body());
            }

            $pageItems = $response->json('items') ?? [];
            array_push($items, ...$pageItems);

            $hasMore = (bool) ($response->json('page_context.has_more_page') ?? (count($pageItems) >= $perPage));
            $page++;
        } while ($hasMore);

        return $items;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchItemsPage(ZohoConnection $connection, int $page, int $perPage = 200): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->resolveOrganizationId($connection);

        $response = Http::withHeaders($this->authHeaders($connection))
            ->timeout(30)->retry(3, 1000)
            ->get($connection->api_domain.'/inventory/v1/items', [
                'organization_id' => $organizationId,
                'page' => $page,
                'per_page' => $perPage,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Zoho items fetch failed on page '.$page.': '.$response->body());
        }

        $items = $response->json('items') ?? [];
        $hasMore = (bool) ($response->json('page_context.has_more_page') ?? (count($items) >= $perPage));

        return ['items' => $items, 'has_more' => $hasMore];
    }

    /**
     * The items LIST endpoint stopped including stock quantity fields for
     * this org (confirmed live 2026-09-23 — no stock_on_hand/available_
     * stock/locations key at all in fetchItemsPage()'s response, despite
     * this org clearly having per-warehouse tracking enabled), while the
     * per-item DETAIL endpoint still returns a full `locations` array with
     * real location_stock_on_hand per warehouse. One extra HTTP call per
     * item — paced by SyncZohoInventoryJob like every other detail-fetch in
     * this codebase, not a bulk fetch.
     *
     * @return array<string, mixed> the item, unwrapped
     */
    public function fetchItemDetail(ZohoConnection $connection, string $itemId): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->resolveOrganizationId($connection);

        $response = Http::withHeaders($this->authHeaders($connection))
            ->timeout(30)->retry(3, 1000)
            ->get($connection->api_domain.'/inventory/v1/items/'.$itemId, [
                'organization_id' => $organizationId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Zoho item detail fetch failed for {$itemId}: ".$response->body());
        }

        $item = $response->json('item');

        if ($item === null) {
            throw new RuntimeException("Zoho item detail response for {$itemId} had no 'item' key: ".$response->body());
        }

        return $item;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(ZohoConnection $connection): array
    {
        return ['Authorization' => 'Zoho-oauthtoken '.$connection->access_token];
    }
}
