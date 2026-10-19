<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\InvoiceDocument;
use App\Models\ZohoConnection;
use App\Models\ZohoSyncCursor;
use App\Services\Inventory\TransactionImportProcessor;
use App\Services\Inventory\TransactionRowsPersister;
use App\Services\Zoho\ZohoBooksApiThrottle;
use App\Services\Zoho\ZohoBooksTransactionClient;
use App\Services\Zoho\ZohoInventoryClient;
use App\Services\Zoho\ZohoTransactionMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Second, independent lane for syncing invoices — identical in shape to
 * SyncZohoTransactionTypeJob(type: 'invoices') but reading from Zoho BOOKS
 * instead of Inventory, and spending ZohoBooksApiThrottle's separate budget
 * instead of ZohoApiThrottle's. Added 2026-10-15, explicit "be careful,
 * avoid any problem" instruction after confirming live that Books and
 * Inventory draw from genuinely independent per-organization quotas
 * (Inventory 10,000/day, Books 24,000/day, tracked via Zoho's own
 * X-Rate-Limit-* headers) — the existing Inventory-sourced invoices sync
 * was the day's confirmed bottleneck (a batch of recent invoices sitting
 * ~2 hours unsynced under heavy Inventory-budget contention). This doesn't
 * replace that path — both run, both upsert into the SAME invoice_items/
 * invoice_documents tables via the SAME idempotent persister (keyed on
 * zoho_document_id/zoho_line_item_id and zoho_invoice_id respectively), so
 * whichever lane reaches a given invoice first "wins" harmlessly and the
 * other's later pass is just a no-op update — there is no way for the two
 * lanes to create duplicates or conflict.
 *
 * Invoices only for now, not all four transaction types — deliberately
 * narrow first step, matching the single type that was actually bottlenecked
 * today. ZohoTransactionMapper/TransactionRowsPersister are reused UNCHANGED
 * (confirmed live the same day: a Books invoice detail response is
 * byte-identical in field names/shape to Inventory's for the same invoice —
 * same organization, same underlying ledger).
 *
 * Uses its OWN cursor key ("invoices_books", via ZohoSyncCursor) — never
 * "invoices" or "invoices_recent" — so it cannot read or overwrite the
 * existing Inventory-path cursors' resume position under any circumstance.
 */
class SyncInvoicesViaBooksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const TYPE = 'invoices';

    private const CURSOR_KEY = 'invoices_books';

    private const MAX_DOCUMENTS_PER_RUN = 8;

    private const WATERMARK_SAFETY_BUFFER_MINUTES = 15;

    private const THROTTLE_RETRY_DELAY_MINUTES = 10;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly array $skuByItemId,
        public readonly ?int $importId = null,
    ) {}

    public function handle(
        ZohoBooksTransactionClient $transactionClient,
        ZohoTransactionMapper $transactionMapper,
        TransactionRowsPersister $persister,
        ZohoBooksApiThrottle $throttle,
        ZohoInventoryClient $inventoryClient,
    ): void {
        $transactionConfig = TransactionImportProcessor::CONFIG[self::TYPE];
        $zohoConfig = ZohoTransactionMapper::zohoConfigFor(self::TYPE);
        $organizationId = $inventoryClient->resolveOrganizationId($this->zohoConnection);

        $import = $this->importId
            ? Import::findOrFail($this->importId)
            : Import::create([
                'user_id' => $this->zohoConnection->user_id,
                'type' => self::CURSOR_KEY,
                // 'zoho', not a distinct 'zoho_books' — confirmed live
                // 2026-10-15 this is NOT just a label: at least 4 separate
                // call sites (ReportsController::document()/transactions(),
                // CustomerController, CurrentInventory) hard-filter
                // `whereIn('source', ['zoho', 'zoho_export'])` to decide
                // what counts as real synced data, so anything tagged
                // differently is silently invisible everywhere despite
                // being correctly persisted — caught live via a 404 on a
                // real invoice (87193) that this job HAD synced correctly.
                // self::CURSOR_KEY already distinguishes this lane for
                // operational purposes (Import.type, alreadyRunning(),
                // cursor identity) — 'source' only needs to answer "is this
                // real Zoho data", and it is.
                'source' => 'zoho',
                'original_filename' => 'Zoho Books sync — '.now()->toDateTimeString(),
                'stored_path' => null,
                'source_signature' => 'zoho_books:'.$this->zohoConnection->id.':'.self::CURSOR_KEY,
                'status' => 'processing',
                'row_count' => 0,
            ]);

        if ($throttle->tooManyAttempts($this->zohoConnection)) {
            $import->touch();
            self::dispatch($this->zohoConnection, $this->skuByItemId, $import->id)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        $cursor = ZohoSyncCursor::firstOrCreate(
            ['zoho_organization_id' => $organizationId, 'type' => self::CURSOR_KEY],
            ['zoho_connection_id' => $this->zohoConnection->id, 'next_page' => 1, 'next_index' => 0],
        );

        $page = max(1, $cursor->next_page ?? 1);
        $index = $cursor->next_index ?? 0;
        $pendingWatermark = $cursor->pending_watermark;
        $documentsThisRun = 0;
        $reachedWatermark = false;
        $budgetExhausted = false;
        $isComplete = false;

        $isInitialBackfill = $cursor->last_modified_watermark === null;
        $sortColumn = $isInitialBackfill ? 'created_time' : 'last_modified_time';
        $sortOrder = $isInitialBackfill ? 'A' : 'D';

        // Server-side filter for steady-state passes, not just sort — same
        // fix as SyncZohoTransactionTypeJob's (2026-10-15/17), applied here
        // too since this job shares the identical page/watermark design and
        // ZohoBooksTransactionClient::fetchPage() confirmed live (2026-10-17)
        // to honor this filter exactly like the Inventory client does.
        $modifiedSinceFilter = $isInitialBackfill ? null : Carbon::parse($cursor->last_modified_watermark)->utc()->format('Y-m-d\TH:i:sO');

        try {
            $throttle->hit($this->zohoConnection);
            $result = $transactionClient->fetchPage($this->zohoConnection, $zohoConfig['endpoint'], $zohoConfig['response_key'], $page, $sortColumn, $sortOrder, $modifiedSinceFilter);
            $pageDocuments = $result['documents'];
            $hasMorePages = $result['has_more'];

            $now = now();
            $itemRows = [];

            while ($index < count($pageDocuments) && $documentsThisRun < self::MAX_DOCUMENTS_PER_RUN) {
                if ($throttle->tooManyAttempts($this->zohoConnection)) {
                    $budgetExhausted = true;
                    break;
                }

                $summary = $pageDocuments[$index];

                if ($isInitialBackfill) {
                    $modifiedTime = $summary['last_modified_time'] ?? null;
                    if ($modifiedTime !== null && ($pendingWatermark === null || Carbon::parse($modifiedTime)->gt(Carbon::parse($pendingWatermark)))) {
                        $pendingWatermark = $modifiedTime;
                    }
                } elseif ($pendingWatermark === null) {
                    $pendingWatermark = $summary['last_modified_time'] ?? null;
                }

                if ($cursor->last_modified_watermark !== null) {
                    $modifiedTime = $summary['last_modified_time'] ?? null;
                    if ($modifiedTime !== null && Carbon::parse($modifiedTime)->lessThanOrEqualTo($cursor->last_modified_watermark)) {
                        $reachedWatermark = true;
                        break;
                    }
                }

                $documentId = (string) ($summary[$zohoConfig['id_field']] ?? '');
                if ($documentId !== '') {
                    $throttle->hit($this->zohoConnection);
                    $detail = $transactionClient->fetchDetail($this->zohoConnection, $zohoConfig['endpoint'], $zohoConfig['detail_response_key'], $documentId);
                    $rows = $transactionMapper->mapToRows($detail, self::TYPE, $import->id, $this->skuByItemId, $now);
                    array_push($itemRows, ...$rows);

                    $invoiceDocument = $transactionMapper->mapToInvoiceDocument($detail);
                    if ($invoiceDocument !== null) {
                        InvoiceDocument::query()->updateOrCreate(
                            ['zoho_invoice_id' => $invoiceDocument['zoho_invoice_id']],
                            $invoiceDocument,
                        );
                    }
                }

                $index++;
                $documentsThisRun++;
            }

            if (! empty($itemRows)) {
                $persister->persistIncremental($itemRows, $transactionConfig['table'], $transactionConfig['date_column']);
                $import->increment('row_count', count($itemRows));
            }

            if ($reachedWatermark) {
                $isComplete = true;
            } elseif ($index >= count($pageDocuments)) {
                if ($hasMorePages) {
                    $page++;
                    $index = 0;
                } else {
                    $isComplete = true;
                }
            }
        } catch (Throwable $e) {
            Log::warning("SyncInvoicesViaBooksJob failed on page {$page} index {$index}, retrying in ".self::THROTTLE_RETRY_DELAY_MINUTES.'m: '.$e->getMessage());
            $import->touch();
            self::dispatch($this->zohoConnection, $this->skuByItemId, $import->id)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        if (! $isComplete) {
            $cursor->update(['next_page' => $page, 'next_index' => $index, 'pending_watermark' => $pendingWatermark]);
            $import->touch();
            $next = self::dispatch($this->zohoConnection, $this->skuByItemId, $import->id);
            if ($budgetExhausted) {
                $next->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));
            }

            return;
        }

        // Steady-state passes anchor to when THIS pass started
        // ($import->created_at), not the newest value observed — same fix
        // as SyncZohoTransactionTypeJob's (2026-10-17): guarantees the next
        // pass's filter still catches anything touched during this pass,
        // regardless of which page it ended up on.
        $newWatermark = $isInitialBackfill
            ? ($pendingWatermark !== null ? Carbon::parse($pendingWatermark)->utc()->subMinutes(self::WATERMARK_SAFETY_BUFFER_MINUTES) : $cursor->last_modified_watermark)
            : Carbon::parse($import->created_at)->utc()->subMinutes(self::WATERMARK_SAFETY_BUFFER_MINUTES);

        $cursor->update([
            'next_page' => 1,
            'next_index' => 0,
            'pending_watermark' => null,
            'last_modified_watermark' => $newWatermark,
        ]);
        $import->update(['status' => 'completed', 'error_message' => null]);
    }
}
