<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Twin of ZohoTransactionClient, hitting Zoho BOOKS (/books/v3/...) instead
 * of Inventory (/inventory/v1/...) — a deliberately separate class, not a
 * parameterized base-path on the existing one, so the proven Inventory path
 * can never be accidentally affected while this is being introduced
 * (2026-10-15, explicit "be careful, avoid any problem" instruction).
 *
 * Safe to feed the SAME ZohoTransactionMapper/ZohoItemMapper as the
 * Inventory path: confirmed live the same day, byte-for-byte, that Books'
 * invoice detail response has the IDENTICAL field names/shape as
 * Inventory's for the same invoice (same organization, same underlying
 * ledger — Zoho Inventory is built on top of the same Books org, not a
 * separate data model). Endpoint/response-key names (e.g. 'invoices' list
 * key, 'invoice' detail key) are also identical — only the host path and
 * the rate-limit pool differ, confirmed via Zoho's own X-Rate-Limit-*
 * response headers tracking two completely independent counters at the
 * same moment (Inventory 10,000/day, Books 24,000/day).
 *
 * Counts against ZohoBooksApiThrottle, never ZohoApiThrottle — the whole
 * point of this class existing is a genuinely separate budget, not a
 * relabeled call into the same one.
 */
class ZohoBooksTransactionClient
{
    public const PER_PAGE = 200;

    public function __construct(
        private readonly ZohoOAuthClient $oauth,
        private readonly ZohoInventoryClient $inventoryClient,
    ) {}

    /**
     * @param  ?string  $modifiedSince  server-side `last_modified_time` filter — same mechanism
     *     confirmed live for ZohoTransactionClient::fetchPage() (see its docblock), confirmed
     *     live here too (2026-10-17) against this same org's Books host. Must be formatted
     *     `Y-m-d\TH:i:sO`. See that docblock for the full reasoning (page-shift immunity).
     * @return array{documents: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchPage(ZohoConnection $connection, string $endpoint, string $responseKey, int $page, string $sortColumn = 'last_modified_time', string $sortOrder = 'D', ?string $modifiedSince = null): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $query = [
            'organization_id' => $organizationId,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'sort_column' => $sortColumn,
            'sort_order' => $sortOrder,
        ];
        if ($modifiedSince !== null) {
            $query['last_modified_time'] = $modifiedSince;
        }

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->timeout(30)->retry(3, 1000)
            ->get($this->booksBaseUrl($connection).'/books/v3/'.$endpoint, $query);

        if (! $response->successful()) {
            throw new RuntimeException("Zoho Books {$endpoint} fetch failed on page {$page}: ".$response->body());
        }

        $documents = $response->json($responseKey) ?? [];
        $hasMore = (bool) ($response->json('page_context.has_more_page') ?? (count($documents) >= self::PER_PAGE));

        return ['documents' => $documents, 'has_more' => $hasMore];
    }

    /**
     * @return array<string, mixed> the document, unwrapped
     */
    public function fetchDetail(ZohoConnection $connection, string $endpoint, string $detailResponseKey, string $documentId): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->timeout(30)->retry(3, 1000)
            ->get($this->booksBaseUrl($connection).'/books/v3/'.$endpoint.'/'.$documentId, [
                'organization_id' => $organizationId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Zoho Books {$endpoint} detail fetch failed for {$documentId}: ".$response->body());
        }

        $document = $response->json($detailResponseKey);

        if ($document === null) {
            throw new RuntimeException("Zoho Books {$endpoint} detail response for {$documentId} had no '{$detailResponseKey}' key: ".$response->body());
        }

        return $document;
    }

    /**
     * $connection->api_domain is a bare host (e.g. https://www.zohoapis.sa)
     * with no path — ZohoTransactionClient appends '/inventory/v1/...'
     * directly onto it the same way. Books lives on this EXACT SAME host,
     * just a different path prefix ('/books/v3/...'), confirmed live (no
     * separate "books api_domain" exists anywhere on the OAuth token
     * response) — so no host rewriting is needed here at all, unlike
     * ZohoAnalyticsClient which genuinely does live on a different host
     * (analyticsapi.zoho.<tld>).
     */
    private function booksBaseUrl(ZohoConnection $connection): string
    {
        return $connection->api_domain;
    }
}
