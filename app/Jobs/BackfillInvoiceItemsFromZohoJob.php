<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\InvoiceDocument;
use App\Models\ZohoConnection;
use App\Services\Inventory\TransactionRowsPersister;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoTransactionClient;
use App\Services\Zoho\ZohoTransactionMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-time backfill for the ~41k invoices that only ever came from the
 * historical Analytics-Excel import (ImportInvoiceItemsFixedReport) and so
 * have no zoho_document_id — meaning no warehouse/discount_amount/sub_total
 * in extra, and no matching invoice_documents row from the live sync path.
 * Confirmed live 2026-09-22 (see diag_backfill_scope.php): 41,142 such
 * documents vs. 1,716 that already have a real zoho_document_id from the
 * live sync.
 *
 * Self-dispatching chunked design, same shape as SyncZohoTransactionTypeJob
 * (this host kills long-running PHP processes) and sharing that job's exact
 * ZohoApiThrottle instance — deliberately NOT a separate/bigger budget, so
 * this backfill can only ever compete for the same safe 300/hour pool
 * everything else already respects, never add to it. At that rate this
 * takes roughly 41,142 / 300 ≈ 137 hours (~5-6 days) to fully drain; that's
 * expected and fine for a one-time historical cleanup — it runs unattended
 * in the background as part of the hourly queue tick, no different from any
 * other Zoho sync type.
 *
 * "Next batch" is always just whatever invoice_items rows currently have
 * zoho_document_id IS NULL — no separate cursor/offset needed, since a
 * successfully backfilled document's rows get a real zoho_document_id and
 * naturally leave that set. Self-correcting if interrupted/resumed anytime.
 *
 * Looks up each document's zoho_invoice_id via invoice_documents (populated
 * by ImportInvoiceDocumentsAnalyticsReport, which DID capture Invoice ID
 * from the same Analytics export) rather than the Zoho list endpoint, since
 * we already know exactly which invoice_number we need — no wasted list-page
 * calls. A document with no invoice_documents match, or whose detail fetch
 * fails (e.g. genuinely deleted in Zoho since), gets marked with a sentinel
 * zoho_document_id so it stops appearing in "next batch" instead of being
 * retried forever and stalling the whole backfill on one bad record.
 */
class BackfillInvoiceItemsFromZohoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const BATCH_SIZE = 5;

    private const THROTTLE_RETRY_DELAY_MINUTES = 10;

    private const UNRESOLVED_SENTINEL = 'BACKFILL_UNRESOLVED';

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly ?int $importId = null,
    ) {}

    public function handle(
        ZohoTransactionClient $transactionClient,
        ZohoTransactionMapper $transactionMapper,
        TransactionRowsPersister $persister,
        ZohoApiThrottle $throttle,
    ): void {
        $import = $this->importId
            ? Import::findOrFail($this->importId)
            : Import::create([
                'user_id' => $this->zohoConnection->user_id,
                'type' => 'invoice_items_backfill',
                'source' => 'zoho',
                'original_filename' => 'Invoice items backfill — '.now()->toDateTimeString(),
                'stored_path' => null,
                'source_signature' => 'zoho:'.$this->zohoConnection->id.':invoice_items_backfill',
                'status' => 'processing',
                'row_count' => 0,
            ]);

        if ($throttle->tooManyAttempts($this->zohoConnection)) {
            $import->touch();
            self::dispatch($this->zohoConnection, $import->id)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        $documentNumbers = DB::table('invoice_items')
            ->whereNull('zoho_document_id')
            ->whereNotNull('document_number')
            ->distinct()
            ->orderBy('document_number')
            ->limit(self::BATCH_SIZE)
            ->pluck('document_number');

        if ($documentNumbers->isEmpty()) {
            $import->update(['status' => 'completed', 'error_message' => null]);
            Log::info('BackfillInvoiceItemsFromZohoJob: no more documents with a null zoho_document_id — backfill complete.');

            return;
        }

        $skuByItemId = InventoryItem::query()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.zoho_item_id')) as zoho_item_id, sku")
            ->whereNotNull('extra')
            ->get()
            ->filter(fn ($row) => $row->zoho_item_id !== null)
            ->pluck('sku', 'zoho_item_id')
            ->all();

        $now = now();
        $budgetExhausted = false;

        foreach ($documentNumbers as $documentNumber) {
            if ($throttle->tooManyAttempts($this->zohoConnection)) {
                $budgetExhausted = true;
                break;
            }

            $zohoInvoiceId = InvoiceDocument::where('invoice_number', $documentNumber)->value('zoho_invoice_id');

            if ($zohoInvoiceId === null) {
                $this->markUnresolved($documentNumber, 'no matching invoice_documents row');

                continue;
            }

            try {
                $throttle->hit($this->zohoConnection);
                $detail = $transactionClient->fetchDetail($this->zohoConnection, 'invoices', 'invoice', $zohoInvoiceId);
                $rows = $transactionMapper->mapToRows($detail, 'invoices', $import->id, $skuByItemId, $now);

                if (empty($rows)) {
                    $this->markUnresolved($documentNumber, 'mapped to zero line items');

                    continue;
                }

                // The old Excel-imported rows have no zoho_document_id/
                // zoho_line_item_id (persistIncremental()'s upsert key), so
                // upserting the freshly-mapped rows would INSERT alongside
                // them rather than replace them — delete the stale rows for
                // this document first, scoped to only the still-unresolved
                // ones so a row some other path already fixed is never
                // touched.
                DB::table('invoice_items')
                    ->where('document_number', $documentNumber)
                    ->whereNull('zoho_document_id')
                    ->delete();

                $persister->persistIncremental($rows, 'invoice_items', 'invoice_date');

                $invoiceDocumentRow = $transactionMapper->mapToInvoiceDocument($detail);
                if ($invoiceDocumentRow !== null) {
                    InvoiceDocument::query()->updateOrCreate(
                        ['zoho_invoice_id' => $invoiceDocumentRow['zoho_invoice_id']],
                        $invoiceDocumentRow,
                    );
                }

                $import->increment('row_count', count($rows));
            } catch (Throwable $e) {
                Log::warning("BackfillInvoiceItemsFromZohoJob: failed on document {$documentNumber} (invoice_id {$zohoInvoiceId}): ".$e->getMessage());
                $this->markUnresolved($documentNumber, 'fetch/map exception: '.$e->getMessage());
            }
        }

        $import->touch();
        $next = self::dispatch($this->zohoConnection, $import->id);
        if ($budgetExhausted) {
            $next->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));
        }
    }

    private function markUnresolved(string $documentNumber, string $reason): void
    {
        Log::warning("BackfillInvoiceItemsFromZohoJob: marking document {$documentNumber} unresolved — {$reason}");

        DB::table('invoice_items')
            ->where('document_number', $documentNumber)
            ->whereNull('zoho_document_id')
            ->update(['zoho_document_id' => self::UNRESOLVED_SENTINEL]);
    }
}
