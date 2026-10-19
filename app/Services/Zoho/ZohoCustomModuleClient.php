<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes records to a Zoho custom module. Uses the /inventory/v1/ domain,
 * not /books/v3/ — confirmed empirically: this module was created "in Zoho
 * Inventory", and probing the API showed /inventory/v1/{module_name}
 * recognized as a real resource (401 not-authorized, meaning right URL,
 * wrong scope) while every /books/v3/ variant returned 404.
 */
class ZohoCustomModuleClient
{
    public function __construct(
        private readonly ZohoOAuthClient $oauth,
        private readonly ZohoInventoryClient $inventoryClient,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @return string the new record's id
     */
    public function create(ZohoConnection $connection, string $moduleName, array $fields): string
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->timeout(30)->retry(3, 1000)
            ->post($connection->api_domain.'/inventory/v1/'.$moduleName, array_merge($fields, [
                'organization_id' => $organizationId,
            ]));

        if (! $response->successful()) {
            throw new RuntimeException("Zoho {$moduleName} create failed: ".$response->body());
        }

        $recordId = $this->findRecordId($response->json());

        if ($recordId === null) {
            // Response shape for a custom module's create record hasn't
            // been confirmed against this org's live API yet — surface the
            // raw body so the first real call tells us exactly what to
            // parse, rather than guessing a path that might be wrong.
            throw new RuntimeException("Zoho {$moduleName} create succeeded (HTTP {$response->status()}) but no record id could be found in the response. Raw body: ".$response->body());
        }

        return (string) $recordId;
    }

    /**
     * Searches the decoded response (top level, then one level into any
     * nested object) for a field literally named "id" or ending in "_id".
     */
    private function findRecordId(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id')) && (is_string($value) || is_int($value))) {
                return (string) $value;
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findRecordId($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Reads every record from a custom module, paginating until Zoho stops
     * reporting more pages. Confirmed live (2026-09-12) against
     * cm_expiry_report: records come back under a "module_records" key and
     * pagination info under "page_context.has_more_page", same shape the
     * org's own Deluge automation script (which built this module in the
     * first place) already relies on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(ZohoConnection $connection, string $moduleName, int $perPage = 200): array
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $records = [];
        $page = 1;

        do {
            $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
                ->timeout(30)->retry(3, 1000)
                ->get($connection->api_domain.'/inventory/v1/'.$moduleName, [
                    'organization_id' => $organizationId,
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException("Zoho {$moduleName} list failed on page {$page}: ".$response->body());
            }

            $pageRecords = $response->json('module_records') ?? [];
            array_push($records, ...$pageRecords);

            $hasMore = (bool) ($response->json('page_context.has_more_page') ?? (count($pageRecords) >= $perPage));
            $page++;
        } while ($hasMore);

        return $records;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function update(ZohoConnection $connection, string $moduleName, string $recordId, array $fields): void
    {
        $this->oauth->refreshIfNeeded($connection);
        $organizationId = $this->inventoryClient->resolveOrganizationId($connection);

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->timeout(30)->retry(3, 1000)
            ->put($connection->api_domain.'/inventory/v1/'.$moduleName.'/'.$recordId, array_merge($fields, [
                'organization_id' => $organizationId,
            ]));

        if (! $response->successful()) {
            throw new RuntimeException("Zoho {$moduleName} update failed for {$recordId}: ".$response->body());
        }
    }
}
