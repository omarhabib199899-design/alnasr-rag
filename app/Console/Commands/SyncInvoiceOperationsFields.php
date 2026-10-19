<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backfills the Sales Operations fields (prepared_by, review_by,
 * delivery_by, invoice_attached_by, exit/delivery date time, rasd, data
 * entry, invoice attachment status, carton/icebox/pallets no) on EVERY
 * already-synced invoice in one shot, from the same Zoho Analytics view
 * config('zoho.analytics.views.invoice_documents') points at (view id
 * 100722000003555784) — replaces waiting days for SyncZohoTransactionTypeJob's
 * live-API created_time backfill to crawl through all ~43,735 invoices one
 * detail-fetch at a time (2026-10-14, user's own "ممكن نحلها مانويل" ask).
 *
 * Confirmed live 2026-10-14 this view is NOT the rich "Invoices" financial
 * export SyncInvoiceDocuments/ImportInvoiceDocumentsAnalyticsReport assumed
 * (that command's "Invoice ID" column lookup fails against it) — it's a
 * much leaner, ops-fields-only view with no Invoice ID / Customer ID at
 * all, keyed by Invoice Number and Customer Number instead. 43,735 data
 * rows — matches the true live Zoho invoice count exactly (confirmed via a
 * binary search against the live API the same day), so this view is a
 * complete, authoritative snapshot of every invoice that exists.
 *
 * UPDATE-only by design, never INSERT: invoice_documents.zoho_invoice_id
 * is NOT NULL UNIQUE and this view has no such id to satisfy it, so rows
 * for invoices we don't have yet (the live-API backfill's job, still
 * running) are silently skipped here rather than guessed at — this command
 * only enriches invoices already present. Uses JSON_SET on the existing
 * `extra` column (not a read-modify-write per row) so it only ever adds/
 * overwrites these 12 specific keys, never touching any other key a prior
 * live-API sync already wrote there (customer_check, no_of_errors_after_
 * delivery, basic_payment, notes_1, total_margin — none of which this
 * lean view carries at all).
 */
class SyncInvoiceOperationsFields extends Command
{
    protected $signature = 'reports:sync-invoice-operations-fields';

    protected $description = 'One-shot backfill of Sales Operations fields (prepared_by, carton_no, etc.) on every already-synced invoice, from Zoho Analytics';

    private const POLL_DELAY_SECONDS = 10;

    private const MAX_POLL_ATTEMPTS = 30;

    private const MIN_PLAUSIBLE_ROWS = 10000;

    /** extra-JSON key => CSV header (exact, confirmed live 2026-10-14). */
    private const FIELDS = [
        'prepared_by' => 'PREPARED BY',
        'review_by' => 'REVIEW BY',
        'delivery_by' => 'DELIVERY BY',
        'invoice_attached_by' => 'INVOICE ATTACHED BY',
        'exit_date_time' => 'EXIT DATE TIME',
        'delivery_date_time' => 'DELIVERY DATE TIME',
        'rasd' => 'RASD',
        'data_entry' => 'DATA ENTRY',
        'invoice_attachment_status' => 'INVOICE ATTACHMENT STATUS',
        'carton_no' => 'CARTON NO',
        'icebox_no' => 'ICEBOX NO',
        'pallets_no' => 'Pallets No',
    ];

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $viewId = (string) config('zoho.analytics.views.invoice_documents');

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
                $this->error('Export job never completed.');

                return self::FAILURE;
            }

            $csv = $client->downloadExportData($connection, $workspaceId, $jobId);
        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $lineCount = substr_count($csv, "\n") + 1;
        if ($lineCount < self::MIN_PLAUSIBLE_ROWS) {
            $this->error("Downloaded export only had {$lineCount} lines — refusing to import it.");

            return self::FAILURE;
        }

        $tmpPath = storage_path('app/zoho-analytics-sync/invoice_ops_'.now()->format('Ymd_His').'.csv');
        if (! is_dir(dirname($tmpPath))) {
            mkdir(dirname($tmpPath), 0755, true);
        }
        file_put_contents($tmpPath, $csv);
        unset($csv);

        $handle = fopen($tmpPath, 'r');
        $headerRow = fgetcsv($handle);
        $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', $headerRow[0]);

        $columnIndex = [];
        foreach ($headerRow as $i => $h) {
            $columnIndex[trim((string) $h)] = $i;
        }

        if (! isset($columnIndex['Invoice Number'])) {
            $this->error('Could not find "Invoice Number" column — found: '.implode(', ', $headerRow));
            fclose($handle);

            return self::FAILURE;
        }

        foreach (self::FIELDS as $field => $header) {
            if (! isset($columnIndex[$header])) {
                $this->error("Expected column '{$header}' not found.");
                fclose($handle);

                return self::FAILURE;
            }
        }

        $updated = 0;
        $skippedNotFound = 0;
        $processed = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            $processed++;
            $invoiceNumber = trim((string) ($row[$columnIndex['Invoice Number']] ?? ''));
            if ($invoiceNumber === '') {
                continue;
            }

            // Chains JSON_SET calls into the existing `extra` value itself —
            // extra = JSON_SET(JSON_SET(JSON_SET(extra, ...), ...), ...) —
            // so every other key already in there survives untouched.
            $expr = 'extra';
            foreach (self::FIELDS as $field => $header) {
                $expr = "JSON_SET({$expr}, '\$.{$field}', ?)";
            }

            $values = [];
            foreach (self::FIELDS as $field => $header) {
                $value = trim((string) ($row[$columnIndex[$header]] ?? ''));
                $values[] = $value !== '' ? $value : null;
            }
            $values[] = $now;
            $values[] = $invoiceNumber;

            $affected = DB::update(
                "UPDATE invoice_documents SET extra = {$expr}, updated_at = ? WHERE invoice_number = ?",
                $values
            );

            if ($affected > 0) {
                $updated++;
            } else {
                $skippedNotFound++;
            }

            if ($processed % 5000 === 0) {
                $this->info("Processed {$processed}, updated {$updated} so far...");
            }
        }

        fclose($handle);
        @unlink($tmpPath);

        $this->info("Done — processed {$processed} rows, updated {$updated} invoices, {$skippedNotFound} not found locally yet (will be caught by the live-API backfill).");

        return self::SUCCESS;
    }
}
