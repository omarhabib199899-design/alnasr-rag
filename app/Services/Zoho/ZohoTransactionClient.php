<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Single-page fetch for Zoho's document-shaped endpoints (sales orders,
 * purchase orders, sales returns, invoices) — same page/per_page/retry
 * shape as ZohoInventoryClient::fetchAllItems, parameterized by endpoint
 * path and response array key since each type varies only in those two
 * strings.
 *
 * Deliberately one page per call, not a full paginated loop: a shared host
 * can silently kill a long-running PHP process (no exception, nothing
 * caught — confirmed happening in production on a large first-time
 * backfill), so SyncZohoTransactionTypeJob fetches a few pages, persists
 * them, and re-queues itself to continue rather than looping here.
 *
 * Defaults to sorting by last_modified_time descending so the caller can
 * stop as soon as it reaches its stored watermark — this is how sync
 * becomes incremental without depending on any unverified Zoho filter_by/
 * last_modified_time query parameter (none of page/per_page/sort_column/
 * sort_order/last_modified_time-as-a-response-field is a guess — all four
 * are already proven against this org, the first three from the original
 * full-fetch loop this replaces and the last from the Deluge contacts API
 * work on the same Zoho account). fetchPage()'s own docblock covers the
 * created_time/ascending alternative used for the one-time initial backfill.
 */
class ZohoTransactionClient
{
    public const PER_PAGE = 200;

    public function __construct(
        private readonly ZohoOAuthClient $oauth,
        private readonly ZohoInventoryClient $inventoryClient,
    ) {}

    /**
     * $sortColumn/$sortOrder default to the original last_modified_time-
     * descending behavior — callers doing a one-off full pass (the initial
     * backfill, before any watermark exists) pass 'created_time'/'A'
     * instead. See SyncZohoTransactionTypeJob's docblock for why: sorting
     * by last_modified_time is only safe for a short-lived pass, because
     * every edit made to ANY document while a multi-day/week crawl is still
     * walking the list re-sorts that document to the top, silently pushing
     * not-yet-visited documents past pages the crawl has already moved on
     * from. created_time never changes once set, so a page's membership is
     * permanent regardless of how long the crawl takes — new documents can
     * only ever append at the end of an ascending created_time list, never
     * insert themselves ahead of the crawl's current position.
     *
     * @param  ?string  $modifiedSince  server-side `last_modified_time` filter — confirmed
     *     live (2026-10-15) against this org that Zoho's list endpoint actually honors this
     *     as a real "modified at or after" filter, not just a sortable response field (it
     *     was never tried before this; the 400 "Invalid value passed for last_modified_time"
     *     response for a wrongly-formatted value is what confirmed the param itself is real).
     *     Must be formatted `Y-m-d\TH:i:sO` (ISO 8601, timezone offset with no colon — e.g.
     *     "2026-10-15T14:28:05+0000"; other formats, including with a colon in the offset,
     *     a bare date, or a unix timestamp, all got a 400). Passing this moves the "does this
     *     document qualify" decision onto Zoho's own server, re-evaluated fresh on every page
     *     request — unlike sorting alone, a document that gets touched (and re-sorted) mid-
     *     crawl can only ever still satisfy the filter, never fall out of the matching set, so
     *     it can shift which page it lands on without ever being silently skipped entirely.
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
            ->get($connection->api_domain.'/inventory/v1/'.$endpoint, $query);

        if (! $response->successful()) {
            throw new RuntimeException("Zoho {$endpoint} fetch failed on page {$page}: ".$response->body());
        }

        $documents = $response->json($responseKey) ?? [];
        $hasMore = (bool) ($response->json('page_context.has_more_page') ?? (count($documents) >= self::PER_PAGE));

        return ['documents' => $documents, 'has_more' => $hasMore];
    }

    /**
     * List responses omit line_items (confirmed against this org's live
     * API) — this fetches one document's full detail, which includes them,
     * wrapped under $detailResponseKey (e.g. 'purchaseorder' for the
     * purchaseorders endpoint).
     *
     * @return array<string, mixed> the document, unwrapped
     */
    public function fetchDetail(ZohoConnection $connection, string $endpoint, string $detailResponseKey, string $documentId): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->timeout(30)->retry(3, 1000)
            ->get($connection->api_domain.'/inventory/v1/'.$endpoint.'/'.$documentId, [
                'organization_id' => $organizationId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Zoho {$endpoint} detail fetch failed for {$documentId}: ".$response->body());
        }

        $document = $response->json($detailResponseKey);

        if ($document === null) {
            throw new RuntimeException("Zoho {$endpoint} detail response for {$documentId} had no '{$detailResponseKey}' key: ".$response->body());
        }

        return $document;
    }
}
