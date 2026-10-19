<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\InvoiceDocument;
use App\Models\ZohoConnection;
use App\Services\Inventory\TransactionImportProcessor;
use App\Services\Inventory\TransactionRowsPersister;
use App\Services\Zoho\ZohoAnalyticsClient;
use App\Services\Zoho\ZohoInventoryClient;
use App\Services\Zoho\ZohoOAuthClient;
use App\Services\Zoho\ZohoTransactionClient;
use App\Services\Zoho\ZohoTransactionMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One-shot targeted close of the invoice gap SyncInvoiceOperationsFields
 * could not fill: invoices that exist in Zoho but have no row in
 * invoice_documents at all. That command is UPDATE-only (its Analytics view
 * has no invoice_id to satisfy invoice_documents.zoho_invoice_id's NOT NULL
 * UNIQUE constraint), so this one instead diffs the Analytics view's
 * invoice-number list against invoice_documents directly, then pulls each
 * missing number individually through the live Invoices API (which does
 * carry invoice_id, customer_id, and full line items) and inserts it for
 * real — rather than waiting however many more days the normal
 * created_time-ascending backfill (ZohoSyncCursor type=invoices) needs to
 * crawl to each of them (2026-10-14, user's own "ممكن نحلها مانويل" /
 * Analytics-query ask, applied here to the small remainder it couldn't
 * reach).
 *
 * Confirmed live the same day: GET /inventory/v1/invoices supports an
 * `invoice_number` search filter that returns the exact single match
 * (tested against invoice 85490 before building this loop) — this is what
 * makes a per-number targeted fetch possible instead of crawling pages.
 */
class FetchMissingInvoices extends Command
{
    protected $signature = 'reports:fetch-missing-invoices';

    protected $description = 'One-shot targeted fetch (via live Zoho API) of invoices present in Zoho Analytics but missing from invoice_documents';

    private const POLL_DELAY_SECONDS = 10;

    private const MAX_POLL_ATTEMPTS = 30;

    private const MIN_PLAUSIBLE_ROWS = 10000;

    public function handle(
        ZohoOAuthClient $oauth,
        ZohoInventoryClient $inventoryClient,
        ZohoAnalyticsClient $analyticsClient,
        ZohoTransactionClient $transactionClient,
        ZohoTransactionMapper $transactionMapper,
        TransactionRowsPersister $persister,
    ): int {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $missingNumbers = $this->findMissingInvoiceNumbers($connection, $analyticsClient);
        if ($missingNumbers === null) {
            return self::FAILURE;
        }

        $this->info('Missing invoices to fetch: '.count($missingNumbers));
        if ($missingNumbers === []) {
            return self::SUCCESS;
        }

        $oauth->refreshIfNeeded($connection);
        $connection->refresh();
        $organizationId = $inventoryClient->resolveOrganizationId($connection);

        $skuByItemId = InventoryItem::query()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.zoho_item_id')) as zoho_item_id, sku")
            ->whereNotNull('extra')
            ->get()
            ->filter(fn ($row) => $row->zoho_item_id !== null)
            ->pluck('sku', 'zoho_item_id')
            ->all();

        $zohoConfig = ZohoTransactionMapper::zohoConfigFor('invoices');
        $transactionConfig = TransactionImportProcessor::CONFIG['invoices'];

        $import = Import::create([
            'user_id' => $connection->user_id,
            'type' => 'invoices_manual_gap_fill',
            'source' => 'zoho',
            'original_filename' => 'Zoho targeted gap fill — '.now()->toDateTimeString(),
            'stored_path' => null,
            'source_signature' => 'zoho:'.$connection->id.':invoices_manual_gap_fill:'.now()->timestamp,
            'status' => 'processing',
            'row_count' => 0,
        ]);

        $fetched = 0;
        $notFoundInZoho = [];
        $failed = [];
        $now = now();

        foreach ($missingNumbers as $invoiceNumber) {
            try {
                $oauth->refreshIfNeeded($connection);
                $connection->refresh();

                $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
                    ->timeout(30)->retry(3, 1000)
                    ->get($connection->api_domain.'/inventory/v1/invoices', [
                        'organization_id' => $organizationId,
                        'invoice_number' => $invoiceNumber,
                    ]);

                if (! $response->successful()) {
                    $failed[] = $invoiceNumber;
                    $this->warn("Search failed for {$invoiceNumber}: ".$response->body());

                    continue;
                }

                $matches = $response->json('invoices') ?? [];
                if ($matches === []) {
                    $notFoundInZoho[] = $invoiceNumber;

                    continue;
                }

                $invoiceId = (string) ($matches[0]['invoice_id'] ?? '');
                if ($invoiceId === '') {
                    $notFoundInZoho[] = $invoiceNumber;

                    continue;
                }

                $detail = $transactionClient->fetchDetail($connection, $zohoConfig['endpoint'], $zohoConfig['detail_response_key'], $invoiceId);

                $rows = $transactionMapper->mapToRows($detail, 'invoices', $import->id, $skuByItemId, $now);
                if ($rows !== []) {
                    $persister->persistIncremental($rows, $transactionConfig['table'], $transactionConfig['date_column']);
                    $import->increment('row_count', count($rows));
                }

                $invoiceDocument = $transactionMapper->mapToInvoiceDocument($detail);
                if ($invoiceDocument !== null) {
                    InvoiceDocument::query()->updateOrCreate(
                        ['zoho_invoice_id' => $invoiceDocument['zoho_invoice_id']],
                        $invoiceDocument,
                    );
                }

                $fetched++;
                $this->info("Fetched invoice {$invoiceNumber} (zoho id {$invoiceId}).");
            } catch (Throwable $e) {
                $failed[] = $invoiceNumber;
                $this->warn("Exception fetching {$invoiceNumber}: ".$e->getMessage());
            }
        }

        $import->update(['status' => 'completed', 'error_message' => null]);

        $this->info("Done — fetched {$fetched} invoices, {$fetched} rows inserted/updated, ".count($notFoundInZoho).' not found in Zoho search, '.count($failed).' failed.');

        if ($notFoundInZoho !== []) {
            $this->warn('Not found in Zoho: '.implode(', ', $notFoundInZoho));
        }
        if ($failed !== []) {
            $this->warn('Failed (retry later): '.implode(', ', $failed));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null null on export failure
     */
    private function findMissingInvoiceNumbers(ZohoConnection $connection, ZohoAnalyticsClient $client): ?array
    {
        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.invoice_documents');

        $this->info('Exporting Analytics invoice view to diff against invoice_documents...');

        try {
            $jobId = $client->createExportJob($connection, $workspaceId, $viewId);

            $status = null;
            for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
                sleep(self::POLL_DELAY_SECONDS);
                $status = $client->getExportJobStatus($connection, $workspaceId, $jobId);
                if ($status['jobStatus'] === 'JOB COMPLETED') {
                    break;
                }
            }

            if ($status === null || $status['jobStatus'] !== 'JOB COMPLETED') {
                $this->error('Export job never completed.');

                return null;
            }

            $csv = $client->downloadExportData($connection, $workspaceId, $jobId);
        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return null;
        }

        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            $this->error("Downloaded export only had {$lineCount} lines — refusing to diff against it.");

            return null;
        }

        // Written to a temp file and read with fgetcsv (not a naive
        // newline-split) so a field containing an embedded newline doesn't
        // silently corrupt the row boundaries — same approach
        // SyncInvoiceOperationsFields uses for the same export.
        $tmpPath = storage_path('app/zoho-analytics-sync/missing_invoice_scan_'.now()->format('Ymd_His').'.csv');
        if (! is_dir(dirname($tmpPath))) {
            mkdir(dirname($tmpPath), 0755, true);
        }
        file_put_contents($tmpPath, $csv);
        unset($csv);

        $handle = fopen($tmpPath, 'r');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
        $idx = array_search('Invoice Number', $header);
        if ($idx === false) {
            $this->error('Could not find "Invoice Number" column in Analytics export.');
            fclose($handle);
            @unlink($tmpPath);

            return null;
        }

        $analyticsNumbers = [];
        while (($row = fgetcsv($handle)) !== false) {
            $num = trim((string) ($row[$idx] ?? ''));
            if ($num !== '') {
                $analyticsNumbers[$num] = true;
            }
        }
        fclose($handle);
        @unlink($tmpPath);

        $existing = DB::table('invoice_documents')->pluck('invoice_number')->filter()->flip()->all();

        $missing = [];
        foreach (array_keys($analyticsNumbers) as $num) {
            if (! isset($existing[$num])) {
                $missing[] = $num;
            }
        }

        return $missing;
    }
}
