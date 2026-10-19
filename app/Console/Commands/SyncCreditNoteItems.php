<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Credit Note line-item detail — the piece Credit Notes needed to get a
 * "View Details" drill-down the same way Invoices already has one (manager's
 * explicit ask, 2026-10-15 voice note). Sourced from TWO Zoho Analytics
 * views joined in PHP, not the live REST API:
 *
 *  - "Credit Note Items" (config('zoho.analytics.views.credit_note_items'))
 *    — Quantity/Total/SKU-level rows, but only carries the internal
 *    "CreditNotes ID", no human-readable number.
 *  - "Credit Notes" (config('zoho.analytics.views.credit_notes')) — has
 *    that number ("Credit Note Number", e.g. "CN-06310"), keyed by the same
 *    CreditNotes ID. Confirmed live this number matches
 *    ar_ledger_entries.transaction_number exactly for credit notes — the
 *    same number CreditNotesController already lists — so the existing
 *    Credit Notes page can link straight into this table's rows with no
 *    further ID translation.
 *
 * Deliberately NOT the live REST /inventory/v1/creditnotes endpoint
 * (confirmed live it exists but needs a new ZohoInventory.creditnotes.READ
 * scope + reconnect) — both Analytics views were already reachable under
 * the existing scope, so this needed no new authorization at all.
 *
 * Full-replace (staging + swap) on every run, same pattern as
 * SyncCollectionClassificationStaging/ImportArLedger — this is a complete
 * snapshot each time, not an incremental crawl, so there's no watermark to
 * maintain and no separate deletion-detection mechanism needed.
 */
class SyncCreditNoteItems extends Command
{
    protected $signature = 'reports:sync-credit-note-items';

    protected $description = 'Refresh credit_note_items live from the Zoho Analytics "Credit Note Items" + "Credit Notes" views';

    private const POLL_DELAY_SECONDS = 15;

    private const MAX_POLL_ATTEMPTS = 40;

    private const MIN_PLAUSIBLE_ROWS = 1000;

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();

        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $workspaceId = (string) config('zoho.analytics.workspace_id');

        try {
            $this->info('Exporting Credit Notes (document-level)...');
            $documents = $this->exportToArray($client, $connection, $workspaceId, (string) config('zoho.analytics.views.credit_notes'));

            $this->info('Exporting Credit Note Items (line-level)...');
            $items = $this->exportToArray($client, $connection, $workspaceId, (string) config('zoho.analytics.views.credit_note_items'));
        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (count($items) < self::MIN_PLAUSIBLE_ROWS) {
            $this->error('Only '.count($items)." line-item rows exported — refusing to replace existing data with it.");

            return self::FAILURE;
        }

        // CreditNotes ID => [number, date, status, customer_id]
        $docHeader = array_shift($documents);
        $docIdx = $this->headerIndex($docHeader);
        foreach (['CreditNotes ID', 'Credit Note Number', 'Credit Note Date', 'Credit Note Status', 'Customer ID'] as $col) {
            if (! isset($docIdx[$col])) {
                $this->error("Credit Notes export missing expected column '{$col}'.");

                return self::FAILURE;
            }
        }
        $docsById = [];
        foreach ($documents as $row) {
            $id = trim((string) ($row[$docIdx['CreditNotes ID']] ?? ''));
            if ($id === '') {
                continue;
            }
            $docsById[$id] = [
                'number' => trim((string) ($row[$docIdx['Credit Note Number']] ?? '')) ?: null,
                'date' => $this->parseDashDate($row[$docIdx['Credit Note Date']] ?? null),
                'status' => trim((string) ($row[$docIdx['Credit Note Status']] ?? '')) ?: null,
                'customer_id' => trim((string) ($row[$docIdx['Customer ID']] ?? '')) ?: null,
            ];
        }
        unset($documents);

        // zoho_customer_id => customer_name, for display
        $customerNames = DB::table('customers')->whereNotNull('zoho_contact_id')->pluck('customer_name', 'zoho_contact_id');

        // zoho_item_id => sku, same mapping every other Zoho sync job uses
        $skuByItemId = DB::table('inventory_items')
            ->whereNotNull('extra')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.zoho_item_id')) as zoho_item_id, sku")
            ->get()
            ->filter(fn ($row) => $row->zoho_item_id !== null)
            ->pluck('sku', 'zoho_item_id');

        $itemHeader = array_shift($items);
        $itemIdx = $this->headerIndex($itemHeader);
        foreach (['Item ID', 'Item Name', 'CreditNotes ID', 'Quantity', 'Total', 'Sub Total', 'Item Price', 'WH', 'Last Modified Time', 'Discount Amount (BCY)'] as $col) {
            if (! isset($itemIdx[$col])) {
                $this->error("Credit Note Items export missing expected column '{$col}'.");

                return self::FAILURE;
            }
        }

        $now = now();
        $staging = 'credit_note_items_'.$now->format('YmdHis').'_'.random_int(1000, 9999);

        try {
            DB::statement("CREATE TABLE `{$staging}` LIKE credit_note_items");

            $batch = [];
            $imported = 0;
            foreach ($items as $row) {
                $creditNoteId = trim((string) ($row[$itemIdx['CreditNotes ID']] ?? ''));
                if ($creditNoteId === '') {
                    continue;
                }
                $doc = $docsById[$creditNoteId] ?? null;
                $zohoItemId = trim((string) ($row[$itemIdx['Item ID']] ?? ''));
                $itemName = trim((string) ($row[$itemIdx['Item Name']] ?? ''));

                $batch[] = [
                    'zoho_document_id' => $creditNoteId,
                    'document_number' => $doc['number'] ?? null,
                    'customer_name' => $doc['customer_id'] !== null ? ($customerNames[$doc['customer_id']] ?? null) : null,
                    'sku' => $skuByItemId[$zohoItemId] ?? ($zohoItemId !== '' ? "ZOHO-{$zohoItemId}" : null),
                    'warehouse' => trim((string) ($row[$itemIdx['WH']] ?? '')) ?: null,
                    'quantity' => $this->toInt($row[$itemIdx['Quantity']] ?? null),
                    'transaction_date' => $doc['date'] ?? null,
                    'status' => $doc['status'] ?? null,
                    'extra' => json_encode([
                        'item_name' => $itemName ?: null,
                        'total' => $this->toFloat($row[$itemIdx['Total']] ?? null),
                        'sub_total' => $this->toFloat($row[$itemIdx['Sub Total']] ?? null),
                        'item_price' => $this->toFloat($row[$itemIdx['Item Price']] ?? null),
                        'discount_amount' => $this->toFloat($row[$itemIdx['Discount Amount (BCY)']] ?? null),
                        'zoho_item_id' => $zohoItemId ?: null,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $imported++;

                if (count($batch) >= 1000) {
                    DB::table($staging)->insert($batch);
                    $batch = [];
                }
            }
            if (! empty($batch)) {
                DB::table($staging)->insert($batch);
            }

            if ($imported < self::MIN_PLAUSIBLE_ROWS) {
                DB::statement("DROP TABLE IF EXISTS `{$staging}`");
                $this->error("Only {$imported} valid rows parsed — refusing to replace existing data with it.");

                return self::FAILURE;
            }

            $old = 'credit_note_items_old_'.$now->format('YmdHis').'_'.random_int(1000, 9999);
            DB::statement("RENAME TABLE credit_note_items TO `{$old}`, `{$staging}` TO credit_note_items");
            DB::statement("DROP TABLE `{$old}`");

            $this->info("Done — {$imported} credit note line items imported (previous snapshot replaced).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::statement("DROP TABLE IF EXISTS `{$staging}`");
            $this->error('Import failed, existing data left untouched: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<int, array<int, string>> first row is the header
     */
    private function exportToArray(ZohoAnalyticsClient $client, ZohoConnection $connection, string $workspaceId, string $viewId): array
    {
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
            throw new \RuntimeException("Export job for view {$viewId} never completed.");
        }

        $csv = $client->downloadExportData($connection, $workspaceId, $jobId);

        $tmpDir = storage_path('app/zoho-analytics-sync');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/credit_note_sync_'.$viewId.'_'.now()->format('Ymd_His').'.csv';
        file_put_contents($tmpPath, $csv);
        unset($csv);

        $rows = [];
        $handle = fopen($tmpPath, 'r');
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        @unlink($tmpPath);

        if (! empty($rows)) {
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', $rows[0][0]);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function headerIndex(array $header): array
    {
        $index = [];
        foreach ($header as $i => $h) {
            $index[trim((string) $h)] = $i;
        }

        return $index;
    }

    private function parseDashDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d-m-Y', $value)->toDateString();
        } catch (Throwable) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (Throwable) {
                return null;
            }
        }
    }

    private function toInt(mixed $value): ?int
    {
        $value = trim((string) str_replace(',', '', (string) $value));

        return $value === '' || ! is_numeric($value) ? null : (int) round((float) $value);
    }

    private function toFloat(mixed $value): ?float
    {
        $value = trim((string) str_replace(',', '', (string) $value));

        return $value === '' || ! is_numeric($value) ? null : (float) $value;
    }
}
