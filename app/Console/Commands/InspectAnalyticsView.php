<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls a small export of a single Zoho Analytics view and prints its
 * header row + a few sample rows — used to confirm a candidate viewId
 * (found by name via zoho:find-classification-views) actually has the
 * column shape we expect, before wiring a real sync job to it. Same
 * bulk-export mechanics as ZohoAnalyticsClient's other callers, just a
 * one-off inspection rather than a full import.
 */
class InspectAnalyticsView extends Command
{
    protected $signature = 'zoho:inspect-analytics-view {viewId} {rows=5}';

    protected $description = 'Export a few rows of a Zoho Analytics view and print its columns for inspection';

    private const POLL_DELAY_SECONDS = 5;

    private const MAX_POLL_ATTEMPTS = 30;

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) $this->argument('viewId');
        $sampleRows = (int) $this->argument('rows');

        try {
            $this->info("Creating export job for view {$viewId}...");
            $jobId = $client->createExportJob($connection, $workspaceId, $viewId);

            $status = null;
            for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
                sleep(self::POLL_DELAY_SECONDS);
                $status = $client->getExportJobStatus($connection, $workspaceId, $jobId);
                $this->info("Poll {$attempt}: {$status['jobStatus']}");

                if ($status['jobStatus'] === 'JOB COMPLETED') {
                    break;
                }
            }

            if ($status === null || $status['jobStatus'] !== 'JOB COMPLETED') {
                $this->error('Export job never completed.');

                return self::FAILURE;
            }

            $csv = $client->downloadExportData($connection, $workspaceId, $jobId);
        } catch (Throwable $e) {
            $this->error('Inspection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $totalRows = count($lines) - 1;

        $this->newLine();
        $this->info("Total rows (incl. header): ".count($lines)." — data rows: {$totalRows}");
        $this->newLine();
        $this->info('=== Header ===');
        $this->line($lines[0] ?? '(empty)');
        $this->newLine();
        $this->info('=== Sample rows ===');
        foreach (array_slice($lines, 1, $sampleRows) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
