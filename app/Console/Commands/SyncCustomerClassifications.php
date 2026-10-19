<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-shot manual pull of the 4 customer classification Query Tables (Sales,
 * Collection, Profitability, Documents) built directly in Zoho Analytics
 * 2026-09-26 from Dr. Ahmed's exact SQL — see project notes. Not scheduled;
 * run manually on demand to avoid burning API budget until this becomes
 * part of the real hourly sync (ZohoSyncService), which is intentionally
 * deferred until the base `customers` table exists (needs the Contacts
 * OAuth scope + reconnect) to attach these rows to.
 *
 * Every row is upserted keyed on (customer_number, type) using the natural
 * "Customer Number" business key (e.g. CUS-14) from Analytics, not our own
 * internal customer id — so this can run and be useful today, independent
 * of the Customers/Contacts sync work, and gets linked up later by a simple
 * join on customer_number once that table exists.
 */
class SyncCustomerClassifications extends Command
{
    protected $signature = 'reports:sync-customer-classifications {--type=* : Limit to one or more of sales,collection,profitability,documents}';

    protected $description = 'Manually pull the 4 customer classification Query Tables from Zoho Analytics into customer_classifications';

    private const POLL_DELAY_SECONDS = 10;

    private const MAX_POLL_ATTEMPTS = 30;

    /**
     * type => [config view key, "Customer Number" header, "Customer Name" header, final classification header]
     */
    private const TYPES = [
        'sales' => ['sales_classification', 'Customer Number', 'Customer Name', 'Sales Classification'],
        'collection' => ['collection_classification', 'Customer Number', 'Customer Name', 'Collection Classification'],
        'profitability' => ['profitability_classification', 'Customer Number', 'Customer Name', 'Profitability Classification'],
        'documents' => ['documents_classification', 'Customer Number', 'Customer Name', 'Documents Classification'],
    ];

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $requestedTypes = $this->option('type') ?: array_keys(self::TYPES);

        foreach ($requestedTypes as $type) {
            if (! isset(self::TYPES[$type])) {
                $this->error("Unknown type '{$type}', skipping. Valid: ".implode(', ', array_keys(self::TYPES)));

                continue;
            }

            [$viewKey, $numberHeader, $nameHeader, $classificationHeader] = self::TYPES[$type];
            $viewId = (string) config("zoho.analytics.views.{$viewKey}");

            if ($viewId === '') {
                $this->error("No view id configured for '{$viewKey}', skipping.");

                continue;
            }

            $this->info("=== {$type} (view {$viewId}) ===");

            try {
                $imported = $this->syncOne($client, $connection, $workspaceId, $viewId, $type, $numberHeader, $nameHeader, $classificationHeader);
                $this->info("Imported/updated {$imported} rows for '{$type}'.");
            } catch (Throwable $e) {
                $this->error("Failed for '{$type}': ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function syncOne(
        ZohoAnalyticsClient $client,
        ZohoConnection $connection,
        string $workspaceId,
        string $viewId,
        string $type,
        string $numberHeader,
        string $nameHeader,
        string $classificationHeader,
    ): int {
        $jobId = $client->createExportJob($connection, $workspaceId, $viewId);

        $status = null;
        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            sleep(self::POLL_DELAY_SECONDS);
            $status = $client->getExportJobStatus($connection, $workspaceId, $jobId);
            $this->line("  poll {$attempt}: {$status['jobStatus']}");

            if ($status['jobStatus'] === 'JOB COMPLETED') {
                break;
            }
        }

        if ($status === null || $status['jobStatus'] !== 'JOB COMPLETED') {
            throw new \RuntimeException('export job never completed');
        }

        $csv = $client->downloadExportData($connection, $workspaceId, $jobId);

        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir."/customer_classification_{$type}_".now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        try {
            return $this->importFromCsvFile($tmpPath, $type, $numberHeader, $nameHeader, $classificationHeader);
        } finally {
            @unlink($tmpPath);
        }
    }

    private function importFromCsvFile(string $path, string $type, string $numberHeader, string $nameHeader, string $classificationHeader): int
    {
        $handle = fopen($path, 'r');

        try {
            $headerRow = fgetcsv($handle);

            if ($headerRow === false) {
                throw new \RuntimeException('empty export');
            }

            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);
            $numberIndex = $this->findColumn($headerRow, $numberHeader);
            $nameIndex = $this->findColumn($headerRow, $nameHeader);
            $classificationIndex = $this->findColumn($headerRow, $classificationHeader);

            if ($numberIndex === false) {
                throw new \RuntimeException("could not find '{$numberHeader}' column in export");
            }

            $now = now();
            $imported = 0;
            $batch = [];

            while (($row = fgetcsv($handle)) !== false) {
                $customerNumber = trim((string) ($row[$numberIndex] ?? ''));

                if ($customerNumber === '') {
                    continue;
                }

                $data = [];
                foreach ($headerRow as $i => $header) {
                    $data[$header] = $row[$i] ?? null;
                }

                $batch[] = [
                    'customer_number' => $customerNumber,
                    'customer_name' => $nameIndex !== false ? ($row[$nameIndex] ?? null) : null,
                    'type' => $type,
                    'classification' => $classificationIndex !== false ? ($row[$classificationIndex] ?? null) : null,
                    'data' => json_encode($data),
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $imported++;

                if (count($batch) >= 500) {
                    $this->upsertBatch($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->upsertBatch($batch);
            }

            return $imported;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Matches a header name loosely: Zoho Analytics names an unaliased
     * `X."Customer Number"` column as "X.Customer Number" in the CSV export
     * (only columns with an explicit AS get the plain name) — so match by
     * "ends with" rather than requiring an exact match.
     */
    private function findColumn(array $headerRow, string $wanted): int|false
    {
        foreach ($headerRow as $i => $header) {
            if ($header === $wanted || str_ends_with($header, '.'.$wanted)) {
                return $i;
            }
        }

        return false;
    }

    private function upsertBatch(array $batch): void
    {
        DB::table('customer_classifications')->upsert(
            $batch,
            ['customer_number', 'type'],
            ['customer_name', 'classification', 'data', 'synced_at', 'updated_at']
        );
    }
}
