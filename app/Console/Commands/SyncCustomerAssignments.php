<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refreshes customers.rep_name/sales_owner/data_entry from the Zoho
 * Analytics "Customers" Table view (config('zoho.analytics.views.
 * customers_directory')) — deliberately ONLY these 3 assignment fields,
 * never any other column on the row (credit limit, balance, status,
 * address, ...), and never creates a customer that doesn't already exist
 * locally.
 *
 * Why these 3 fields specifically come from Analytics while the rest of
 * the customer record stays sourced from the live Contacts API
 * (ZohoContactSyncJob) — explicit decision, 2026-10-17:
 *
 * 1. They change rarely (a rep/owner/data-entry reassignment, not a
 *    constantly-moving number like a balance) — Analytics' own ~2 hour
 *    lag behind live Zoho (confirmed live the same day against the
 *    "Credit Notes" view's Last Modified Time) is irrelevant for data that
 *    doesn't change minute to minute, unlike a balance or an invoice.
 * 2. One bulk export reads all ~1,498 customers in roughly 10-20 seconds,
 *    entirely off Zoho Analytics' own API surface — doesn't touch
 *    ZohoApiThrottle's shared 500/hour Inventory budget at all, the exact
 *    budget the live Contacts sync, every transaction sync, and every
 *    reconcile command above all compete for.
 * 3. It's immune to the page-shift skip bug by construction (a fresh full
 *    snapshot every run, not an incremental page/watermark crawl) — this
 *    is exactly what exposed the gap this command fixes: confirmed live
 *    the same day that CUS-1512/CUS-1513's real "Walid Metwaly" Data Entry
 *    assignment had been sitting unsynced for days because
 *    ZohoContactClient had no server-side last_modified_time filter yet
 *    (since fixed — see that class's docblock — but the existing gap still
 *    needed closing, and 74 of 1,498 customers were affected).
 *
 * What stays on the live API and why this command never creates rows: a
 * brand-new customer can be up to ~2 hours away from appearing in this
 * Analytics view, and a staff member being unable to find a customer
 * created minutes ago is worse than their rep/owner/data_entry assignment
 * being briefly unset — so customer DISCOVERY stays exclusively on
 * ZohoContactSyncJob, matched here by zoho_contact_id against rows that
 * already exist.
 */
class SyncCustomerAssignments extends Command
{
    protected $signature = 'reports:sync-customer-assignments';

    protected $description = 'Refresh customers.rep_name/sales_owner/data_entry from the Zoho Analytics "Customers" view';

    private const POLL_DELAY_SECONDS = 10;

    private const MAX_POLL_ATTEMPTS = 30;

    private const MIN_PLAUSIBLE_ROWS = 1000;

    private const FIELDS = [
        'Rep Name' => 'rep_name',
        'SALES OWNER' => 'sales_owner',
        'Data Entry' => 'data_entry',
    ];

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.customers_directory');

        try {
            $this->info('Creating export job...');
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
                $this->error('Export job never completed — existing data left untouched.');

                return self::FAILURE;
            }

            $csv = $client->downloadExportData($connection, $workspaceId, $jobId);
        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            $this->error("Downloaded export only had {$lineCount} lines — refusing to apply it, existing data left untouched.");

            return self::FAILURE;
        }

        // Written to a temp file and read with fgetcsv (not a naive
        // newline-split) so a field containing an embedded newline doesn't
        // silently corrupt row boundaries — same approach every other
        // Analytics CSV importer in this project uses.
        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/customer_assignments_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        $handle = fopen($tmpPath, 'r');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);

        $idIdx = array_search('Customer ID', $header);
        $fieldIdx = [];
        foreach (self::FIELDS as $column => $dbColumn) {
            $idx = array_search($column, $header);
            if ($idx === false) {
                $this->error("Column '{$column}' not found in export — aborting, existing data left untouched.");
                fclose($handle);
                @unlink($tmpPath);

                return self::FAILURE;
            }
            $fieldIdx[$dbColumn] = $idx;
        }

        $updated = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            $contactId = trim((string) ($row[$idIdx] ?? ''));
            if ($contactId === '') {
                continue;
            }

            $attributes = [];
            foreach ($fieldIdx as $dbColumn => $idx) {
                $value = trim((string) ($row[$idx] ?? ''));
                $attributes[$dbColumn] = $value === '' ? null : $value;
            }

            // Matched against existing rows only — never creates a customer
            // (see class docblock: discovery stays on the live Contacts
            // sync). A row for a contact_id not present locally yet is
            // silently skipped here and picked up normally once
            // ZohoContactSyncJob syncs it.
            $affected = DB::table('customers')
                ->where('zoho_contact_id', $contactId)
                ->where(function ($q) use ($attributes) {
                    foreach ($attributes as $column => $value) {
                        $q->orWhere(function ($qq) use ($column, $value) {
                            if ($value === null) {
                                $qq->whereNotNull($column)->where($column, '!=', '');
                            } else {
                                $qq->whereNull($column)->orWhere($column, '!=', $value);
                            }
                        });
                    }
                })
                ->update($attributes + ['updated_at' => $now]);

            if ($affected > 0) {
                $updated++;
            }
        }

        fclose($handle);
        @unlink($tmpPath);

        $this->info("Done — {$updated} customer(s) had rep_name/sales_owner/data_entry corrected from live Zoho Analytics.");

        return self::SUCCESS;
    }
}
