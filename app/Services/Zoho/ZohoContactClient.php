<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * List + detail fetch for Zoho's /contacts endpoint, same shape as
 * ZohoTransactionClient — one page per call (this host kills long-running
 * PHP processes, see SyncZohoTransactionTypeJob's docblock), sorted by
 * last_modified_time descending so incremental sync can stop at a stored
 * watermark.
 *
 * The list response's exact field set for this org hasn't been confirmed
 * live yet (blocked on ZohoInventory.contacts.READ, a new scope requiring a
 * reconnect) — ZohoContactSyncJob deliberately fetches full per-contact
 * detail for every contact rather than trying to get by on list fields, and
 * ZohoContactMapper stores the complete raw detail payload untouched
 * (customers.live_contact_data) specifically so field names can be
 * confirmed against real data before precise column extraction is written,
 * same as every other Zoho export in this project.
 */
class ZohoContactClient
{
    public const PER_PAGE = 200;

    public function __construct(
        private readonly ZohoOAuthClient $oauth,
        private readonly ZohoInventoryClient $inventoryClient,
    ) {}

    /**
     * @param  ?string  $modifiedSince  server-side `last_modified_time` filter, same mechanism
     *     confirmed live for ZohoTransactionClient::fetchPage() (see its docblock) — Zoho honors
     *     this as a real "modified at or after" filter on /contacts too, not just a sortable
     *     response field. Must be formatted `Y-m-d\TH:i:sO`. Passing this moves the "does this
     *     contact qualify" decision onto Zoho's own server, re-evaluated fresh on every page
     *     request — a contact touched mid-crawl can shift which page it lands on without ever
     *     falling out of the matching set, unlike sorting alone.
     * @return array{contacts: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchPage(ZohoConnection $connection, int $page, ?string $modifiedSince = null): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $query = [
            'organization_id' => $organizationId,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'sort_column' => 'last_modified_time',
            'sort_order' => 'D',
            'contact_type' => 'customer',
        ];
        if ($modifiedSince !== null) {
            $query['last_modified_time'] = $modifiedSince;
        }

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->timeout(30)->retry(3, 1000)
            ->get($connection->api_domain.'/inventory/v1/contacts', $query);

        if (! $response->successful()) {
            throw new RuntimeException("Zoho contacts fetch failed on page {$page}: ".$response->body());
        }

        $contacts = $response->json('contacts') ?? [];
        $hasMore = (bool) ($response->json('page_context.has_more_page') ?? (count($contacts) >= self::PER_PAGE));

        return ['contacts' => $contacts, 'has_more' => $hasMore];
    }

    /**
     * @return array<string, mixed> the contact, unwrapped
     */
    public function fetchDetail(ZohoConnection $connection, string $contactId): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->timeout(30)->retry(3, 1000)
            ->get($connection->api_domain.'/inventory/v1/contacts/'.$contactId, [
                'organization_id' => $organizationId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Zoho contact detail fetch failed for {$contactId}: ".$response->body());
        }

        $contact = $response->json('contact');

        if ($contact === null) {
            throw new RuntimeException("Zoho contact detail response for {$contactId} had no 'contact' key: ".$response->body());
        }

        return $contact;
    }
}
