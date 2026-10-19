<?php

namespace App\Services\Zoho;

use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Zoho Analytics' Bulk API v2 (asynchronous export)
 * for pulling a Query Table's rows directly via API instead of waiting on
 * a manual CSV/XLSX export from the manager. Confirmed live 2026-09-23
 * against the "Sales Item Performance" view (766,521 rows, exact column
 * match with the manual export it replaces).
 *
 * The Analytics API lives on a different host than Inventory/Books
 * (analyticsapi.zoho.<tld> vs www.zohoapis.<tld>) but shares the same
 * OAuth token — no separate auth flow needed, just a different base URL
 * and an extra ZANALYTICS-ORGID header (the Analytics-side org id, a
 * different number from the Inventory zoho_organization_id).
 *
 * Synchronous export ("view data" without /bulk/) is rejected by Zoho for
 * tables of any real size ("SYNC_EXPORT_NOT_ALLOWED") — the bulk/async
 * flow (create job -> poll status -> download) is mandatory here. All
 * three bulk endpoints are GET requests with the CONFIG payload passed as
 * a URL-encoded JSON query parameter, not a POST body — sending it as a
 * POST body (even with the right Content-Type) gets silently ignored by
 * Zoho and returns errorCode 8504 "less than required count".
 */
class ZohoAnalyticsClient
{
    public function __construct(private readonly ZohoOAuthClient $oauth) {}

    /**
     * Creates an asynchronous bulk export job for a view and returns its
     * jobId. Poll getExportJobStatus() until jobStatus is "JOB COMPLETED",
     * then call downloadExportData().
     */
    public function createExportJob(ZohoConnection $connection, string $workspaceId, string $viewId, array $config = ['responseFormat' => 'csv']): string
    {
        $this->oauth->refreshIfNeeded($connection);

        $response = Http::withHeaders($this->headers($connection))
            ->timeout(60)
            ->get($this->baseUrl($connection)."/restapi/v2/bulk/workspaces/{$workspaceId}/views/{$viewId}/data", [
                'CONFIG' => json_encode($config),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Zoho Analytics export job creation failed: '.$response->body());
        }

        $jobId = $response->json('data.jobId');

        if (! $jobId) {
            throw new RuntimeException('Zoho Analytics export job creation returned no jobId: '.$response->body());
        }

        return (string) $jobId;
    }

    /**
     * @return array{jobStatus: string, downloadUrl: ?string}
     */
    public function getExportJobStatus(ZohoConnection $connection, string $workspaceId, string $jobId): array
    {
        $this->oauth->refreshIfNeeded($connection);

        $response = Http::withHeaders($this->headers($connection))
            ->timeout(30)
            ->get($this->baseUrl($connection)."/restapi/v2/bulk/workspaces/{$workspaceId}/exportjobs/{$jobId}");

        if (! $response->successful()) {
            throw new RuntimeException('Zoho Analytics export job status check failed: '.$response->body());
        }

        return [
            'jobStatus' => (string) $response->json('data.jobStatus'),
            'downloadUrl' => $response->json('data.downloadUrl'),
        ];
    }

    /**
     * Downloads the completed export job's data (raw CSV body — caller
     * parses it, same shape as the manual Zoho Analytics CSV export it
     * replaces).
     */
    public function downloadExportData(ZohoConnection $connection, string $workspaceId, string $jobId): string
    {
        $this->oauth->refreshIfNeeded($connection);

        $response = Http::withHeaders($this->headers($connection))
            ->timeout(120)
            ->get($this->baseUrl($connection)."/restapi/v2/bulk/workspaces/{$workspaceId}/exportjobs/{$jobId}/data");

        if (! $response->successful()) {
            throw new RuntimeException('Zoho Analytics export data download failed: '.$response->body());
        }

        return $response->body();
    }

    /**
     * Streams a completed export job's data straight to a file instead of
     * holding it in memory — needed for the raw tables (Accrual
     * Transactions alone is ~2.15M rows), far beyond what
     * downloadExportData()'s in-memory string can safely hold on this host.
     */
    public function downloadExportDataToFile(ZohoConnection $connection, string $workspaceId, string $jobId, string $path): void
    {
        $this->oauth->refreshIfNeeded($connection);

        $response = Http::withHeaders($this->headers($connection))
            ->timeout(1800)
            ->sink($path)
            ->get($this->baseUrl($connection)."/restapi/v2/bulk/workspaces/{$workspaceId}/exportjobs/{$jobId}/data");

        if (! $response->successful()) {
            $body = is_file($path) ? (string) file_get_contents($path, false, null, 0, 500) : '';
            @unlink($path);

            throw new RuntimeException('Zoho Analytics export data download failed: '.$body);
        }
    }

    /**
     * One view's metadata (name, type, columns with dataTypeName). The
     * CONFIG parameter is required — without it Zoho returns an empty body.
     *
     * @return array<string, mixed>
     */
    public function getViewDetails(ZohoConnection $connection, string $viewId): array
    {
        $this->oauth->refreshIfNeeded($connection);

        $response = Http::withHeaders($this->headers($connection))
            ->timeout(30)
            ->get($this->baseUrl($connection)."/restapi/v2/views/{$viewId}", [
                'CONFIG' => json_encode(['withInvolvedMetaInfo' => true]),
            ]);

        if (! $response->successful() || ! is_array($response->json('data.views'))) {
            throw new RuntimeException('Zoho Analytics view details failed: '.$response->body());
        }

        return $response->json('data.views');
    }

    /**
     * Lists every view (table/query table/report) in a workspace — used to
     * discover viewIds by name rather than needing them handed over
     * manually. Same host/auth as the bulk export endpoints.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listViews(ZohoConnection $connection, string $workspaceId): array
    {
        $this->oauth->refreshIfNeeded($connection);

        $response = Http::withHeaders($this->headers($connection))
            ->timeout(30)
            ->get($this->baseUrl($connection)."/restapi/v2/workspaces/{$workspaceId}/views");

        if (! $response->successful()) {
            throw new RuntimeException('Zoho Analytics view listing failed: '.$response->body());
        }

        return $response->json('data.views') ?? [];
    }

    private function baseUrl(ZohoConnection $connection): string
    {
        return str_replace('www.zohoapis', 'analyticsapi.zoho', $connection->api_domain);
    }

    /**
     * @return array<string, string>
     */
    private function headers(ZohoConnection $connection): array
    {
        return [
            'Authorization' => 'Zoho-oauthtoken '.$connection->access_token,
            'ZANALYTICS-ORGID' => (string) config('zoho.analytics.org_id'),
        ];
    }
}
