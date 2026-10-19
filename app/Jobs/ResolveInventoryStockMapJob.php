<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Populates the "zoho_inventory_stock_map:{importId}" cache entry
 * SyncZohoInventoryJob reads stock-on-hand from, via a Zoho Analytics bulk
 * export of the "INVENTORY DATA" view — split into its OWN self-redispatching
 * chain (create job -> redispatch with delay -> poll -> redispatch with
 * delay -> ... -> download) instead of blocking inside one execution with
 * sleep(), which is what the first version of this fix (2026-10-14) did
 * directly inside SyncZohoInventoryJob. That blocked for up to 300 seconds
 * polling Zoho — comfortably past this host's `queue:work --max-time=50`
 * ceiling (see routes/console.php's process-queued-imports entry), so the
 * worker process got killed mid-poll before the job could ever self-
 * redispatch or even log a failure. Confirmed live: the import stopped
 * being touched at all for 10+ minutes with nothing in failed_jobs — a
 * silent, unrecoverable stall, the exact same class of failure
 * SyncZohoInventoryJob/SyncZohoTransactionTypeJob's own docblocks already
 * warn this host is prone to for any one long blocking call. Every wait
 * here is a delayed self-redispatch instead, same pattern as every other
 * chained job in this codebase.
 */
class ResolveInventoryStockMapJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const POLL_DELAY_SECONDS = 15;

    private const MAX_POLL_ATTEMPTS = 20;

    private const STOCK_MAP_CACHE_TTL_HOURS = 3;

    private const MIN_PLAUSIBLE_ROWS = 1000;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly int $importId,
        // Carried through purely so the triggering SyncZohoInventoryJob
        // execution can be resumed at the exact page/index it was at
        // once the map is ready — never read by this job's own logic.
        public readonly int $resumePage,
        public readonly int $resumeIndex,
        public readonly ?string $jobId = null,
        public readonly int $attempt = 0,
    ) {}

    public function handle(ZohoAnalyticsClient $client): void
    {
        $cacheKey = "zoho_inventory_stock_map:{$this->importId}";

        // Another chained execution already finished this since we were
        // queued (e.g. two SyncZohoInventoryJob executions both found the
        // cache missing before either had a chance to react) — nothing left
        // to do, just let the item sync continue from where it was.
        if (Cache::has($cacheKey)) {
            SyncZohoInventoryJob::dispatch($this->zohoConnection, $this->importId, $this->resumePage, $this->resumeIndex);

            return;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.inventory_stock');

        try {
            if ($this->jobId === null) {
                $jobId = $client->createExportJob($this->zohoConnection, $workspaceId, $viewId);
                self::dispatch($this->zohoConnection, $this->importId, $this->resumePage, $this->resumeIndex, $jobId, 0)
                    ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

                return;
            }

            $status = $client->getExportJobStatus($this->zohoConnection, $workspaceId, $this->jobId);

            if ($status['jobStatus'] !== 'JOB COMPLETED') {
                if ($this->attempt >= self::MAX_POLL_ATTEMPTS) {
                    throw new RuntimeException("Inventory stock export job never completed after {$this->attempt} polls (last status: {$status['jobStatus']}).");
                }

                self::dispatch($this->zohoConnection, $this->importId, $this->resumePage, $this->resumeIndex, $this->jobId, $this->attempt + 1)
                    ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

                return;
            }

            $csv = $client->downloadExportData($this->zohoConnection, $workspaceId, $this->jobId);
            $stockByItemId = $this->parseStockCsv($csv);

            Cache::put($cacheKey, $stockByItemId, now()->addHours(self::STOCK_MAP_CACHE_TTL_HOURS));

            SyncZohoInventoryJob::dispatch($this->zohoConnection, $this->importId, $this->resumePage, $this->resumeIndex);
        } catch (Throwable $e) {
            // Same "never silently kill the chain" principle as every other
            // job here: fail the Import loudly (visible in its own status/
            // error_message) rather than letting it sit "processing"
            // forever with no further redispatch. The LAST good inventory
            // snapshot is untouched either way (see SyncZohoInventoryJob's
            // own docblock) — nothing is lost, the dashboard just keeps
            // showing yesterday's numbers until the next scheduled attempt.
            Log::warning("ResolveInventoryStockMapJob failed for import {$this->importId}: ".$e->getMessage());
            Import::whereKey($this->importId)->update([
                'status' => 'failed',
                'error_message' => 'Inventory stock Analytics export failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, int> zoho_item_id => stock
     */
    private function parseStockCsv(string $csv): array
    {
        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            // A silently-truncated export would zero out most items' stock
            // while still passing SyncZohoInventoryJob's row-COUNT-based
            // "new generation >= 80% of previous" safety check (that check
            // counts rows, not stock values) — refuse outright.
            throw new RuntimeException("Inventory stock export only had {$lineCount} lines — refusing to use it.");
        }

        $tmpPath = storage_path('app/zoho-analytics-sync/inventory_stock_'.now()->format('Ymd_His').'.csv');
        if (! is_dir(dirname($tmpPath))) {
            mkdir(dirname($tmpPath), 0755, true);
        }
        file_put_contents($tmpPath, $csv);
        unset($csv);

        $handle = fopen($tmpPath, 'r');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
        $itemIdIdx = array_search('Item ID', $header);
        $quantityIdx = array_search('Quantity', $header);

        if ($itemIdIdx === false || $quantityIdx === false) {
            fclose($handle);
            @unlink($tmpPath);
            throw new RuntimeException('Inventory stock export missing "Item ID" or "Quantity" column — found: '.implode(', ', $header));
        }

        $stockByItemId = [];
        while (($row = fgetcsv($handle)) !== false) {
            $itemId = trim((string) ($row[$itemIdIdx] ?? ''));
            if ($itemId === '') {
                continue;
            }
            $quantity = (int) round((float) str_replace(',', '', (string) ($row[$quantityIdx] ?? '0')));
            $stockByItemId[$itemId] = ($stockByItemId[$itemId] ?? 0) + $quantity;
        }
        fclose($handle);
        @unlink($tmpPath);

        return $stockByItemId;
    }
}
