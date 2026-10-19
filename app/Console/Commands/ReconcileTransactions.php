<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\InventoryItem;
use App\Models\InvoiceDocument;
use App\Models\ZohoConnection;
use App\Services\Inventory\TransactionImportProcessor;
use App\Services\Inventory\TransactionRowsPersister;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoTransactionClient;
use App\Services\Zoho\ZohoTransactionMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Full periodic sweep against Zoho's live document list for one transaction
 * type (sales/purchase_orders/returns/invoices) — the exact same safety net
 * ReconcileCustomers already proved for Contacts (2026-10-05), extended here
 * because SyncZohoTransactionTypeJob's incremental upsert has the identical
 * structural blind spot: it only ever adds/updates via page/watermark
 * crawling, so it can never notice a document DELETED in Zoho, and (same
 * page-shift failure mode documented on both SyncZohoTransactionTypeJob and
 * ReconcileCustomers) an edit can get silently skipped if the document moves
 * to an already-passed page between runs.
 *
 * Raised directly by the manager's own description of the real risk here
 * (2026-10-14 voice note): Zoho Inventory/Books allows editing — and
 * deleting — a purchase bill months after the fact, unlike sales documents
 * which are typically voided (a status change, which the incremental sync
 * DOES catch) rather than hard-deleted. Confirmed live the same day that
 * Zoho Inventory/Books has no delete-event webhook or deleted-items API at
 * all (unlike Zoho CRM) — periodic full-list diffing is the only mechanism
 * this product actually exposes, so this is not a stopgap, it's the
 * strongest fix available against this specific platform's real
 * constraints.
 *
 * Diffs at the DOCUMENT level (one Zoho id can cover several line-item
 * rows): builds Zoho's full live id=>last_modified_time map via list-page
 * calls only (cheap — e.g. ~26 calls for 5,056 purchase orders at 200/page),
 * then compares against our own distinct zoho_document_id set. A real delta
 * costs one fetchDetail() per document, same mapper/persister the hourly
 * job itself uses — this never duplicates that logic.
 *
 * Flags missing documents via zoho_missing_since (every line item sharing
 * that document's zoho_document_id), never deletes — same "never delete
 * data" practice as customers.zoho_missing_since. Throttle-aware like
 * ReconcileCustomers: bails before comparing if the live-list fetch itself
 * ran out of budget mid-way (a partial list would wrongly flag real
 * documents as deleted).
 */
class ReconcileTransactions extends Command
{
    protected $signature = 'reports:reconcile-transactions {type : sales|purchase_orders|returns|invoices}';

    protected $description = 'Compare one transaction type against Zoho\'s full live document list; backfill anything missing, catch up anything edited but silently skipped, flag anything no longer in Zoho';

    private const VALID_TYPES = ['sales', 'purchase_orders', 'returns', 'invoices'];

    private const THROTTLE_WAIT_DELAY_SECONDS = 60;

    private const THROTTLE_WAIT_MAX_ATTEMPTS = 10;

    /**
     * How long a partially-built live-document list survives between runs
     * before being discarded and restarted from page 1 — generous (this
     * type's scheduled cadence is at most every 4 hours, invoices/sales
     * every 12) so a run that gets interrupted by budget exhaustion doesn't
     * lose its progress before the next scheduled attempt can resume it.
     */
    private const LIST_PROGRESS_TTL_HOURS = 24;

    public function handle(ZohoTransactionClient $client, ZohoTransactionMapper $mapper, TransactionRowsPersister $persister, ZohoApiThrottle $throttle): int
    {
        $type = $this->argument('type');
        if (! in_array($type, self::VALID_TYPES, true)) {
            $this->error("Unknown type '{$type}' — must be one of: ".implode(', ', self::VALID_TYPES));

            return self::FAILURE;
        }

        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();
        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        // Confirmed live 2026-10-15: this budget is a rolling window shared
        // with ~10 other concurrent sync consumers, and it can swing from
        // fully exhausted (500/500) to wide open (77/500) within 15 minutes
        // as those jobs' own hits age out — a single check exactly at this
        // command's scheduled :30 mark kept losing that lottery, so this
        // command never actually ran all day despite needing only ~25-30
        // calls. Retrying for a few minutes within the SAME invocation is
        // safe here (unlike a queued job): this runs directly off cron's
        // schedule:run, not through the host's 50-second-capped
        // `queue:work`, so there's no risk of a mid-wait kill the way
        // SyncZohoInventoryJob's original sleep()-based fix had.
        for ($attempt = 1; $throttle->tooManyAttempts($connection); $attempt++) {
            if ($attempt > self::THROTTLE_WAIT_MAX_ATTEMPTS) {
                $message = "reports:reconcile-transactions {$type}: Zoho API budget still exhausted after waiting — giving up for this run. Next scheduled run will retry.";
                $this->warn($message);
                // Scheduled runs pipe console output to /dev/null (see
                // routes/console.php), so $this->warn() alone is silently
                // lost — confirmed live 2026-10-17 this exact path had been
                // failing unnoticed for two days straight (no "invoices_
                // reconcile"/"sales_reconcile" Import row ever created) with
                // nothing anywhere recording that it happened. Log::warning
                // always lands in storage/logs/laravel.log regardless of how
                // the command was invoked, so a failing safety net is at
                // least visible there instead of invisible everywhere.
                Log::warning($message);

                return self::FAILURE;
            }
            $this->warn("Zoho API budget exhausted — waiting ".self::THROTTLE_WAIT_DELAY_SECONDS."s for room (attempt {$attempt}/".self::THROTTLE_WAIT_MAX_ATTEMPTS.')...');
            sleep(self::THROTTLE_WAIT_DELAY_SECONDS);
        }

        $zohoConfig = ZohoTransactionMapper::zohoConfigFor($type);
        $transactionConfig = TransactionImportProcessor::CONFIG[$type];
        $table = $transactionConfig['table'];

        // Resumable across runs (2026-10-17) — confirmed live this list
        // alone can need ~190 calls for invoices, easily more than one
        // scheduled run's share of a budget shared with ~10 other
        // consumers; previously a run that got interrupted here discarded
        // everything and the next run started over from page 1, so a type
        // under sustained heavy contention could keep restarting forever
        // without ever finishing the list, let alone reaching the compare/
        // backfill step. Sorted by created_time ascending (never
        // last_modified_time) specifically so this is safe to resume hours
        // later — same reasoning as SyncZohoTransactionTypeJob's own
        // initial-backfill fix (see its docblock): created_time is
        // immutable, so a page's membership is permanent regardless of
        // what gets edited in Zoho between this run and the next, unlike
        // last_modified_time where an edit elsewhere can re-sort a
        // not-yet-seen document onto a page already passed.
        $progressKey = "reconcile_transactions_list_progress:{$type}";
        $saved = Cache::get($progressKey);
        $page = $saved['page'] ?? 1;
        $zohoLastModified = $saved['zohoLastModified'] ?? []; // zoho_document_id => last_modified_time (raw string, Zoho's +0300 offset)
        if ($saved !== null) {
            $this->info("Resuming list fetch from page {$page} (".count($zohoLastModified)." documents already collected).");
        }

        $this->info("Fetching full live {$type} list from Zoho...");
        $budgetRanOutDuringList = false;

        try {
            $hasMore = true;
            while ($hasMore) {
                if ($throttle->tooManyAttempts($connection)) {
                    $budgetRanOutDuringList = true;
                    break;
                }
                $throttle->hit($connection);
                $result = $client->fetchPage($connection, $zohoConfig['endpoint'], $zohoConfig['response_key'], $page, 'created_time', 'A');
                foreach ($result['documents'] as $doc) {
                    $id = (string) ($doc[$zohoConfig['id_field']] ?? '');
                    if ($id !== '') {
                        $zohoLastModified[$id] = $doc['last_modified_time'] ?? null;
                    }
                }
                $hasMore = $result['has_more'];
                $page++;
            }
        } catch (Throwable $e) {
            $this->error('Failed to fetch live document list: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($budgetRanOutDuringList) {
            Cache::put($progressKey, ['page' => $page, 'zohoLastModified' => $zohoLastModified], now()->addHours(self::LIST_PROGRESS_TTL_HOURS));

            // Same reasoning as ReconcileCustomers: a partial list would
            // wrongly flag every id on a page never reached as "missing".
            $message = "reports:reconcile-transactions {$type}: Zoho API budget ran out while still fetching the document list (page {$page}, ".count($zohoLastModified)." documents so far) — progress saved, next scheduled run will resume from here instead of comparing a partial list.";
            $this->warn($message);
            Log::warning($message); // see the other giving-up path above for why this is here

            return self::FAILURE;
        }

        // Full list completed successfully — any earlier partial progress
        // for this type is now stale and would otherwise sit in cache for
        // up to LIST_PROGRESS_TTL_HOURS confusing the next run.
        Cache::forget($progressKey);

        $zohoIds = array_keys($zohoLastModified);
        $this->info('Zoho live documents: '.count($zohoIds));

        $ourRows = DB::table($table)
            ->selectRaw('zoho_document_id, MAX(updated_at) as updated_at')
            ->whereNotNull('zoho_document_id')
            ->groupBy('zoho_document_id')
            ->get()
            ->pluck('updated_at', 'zoho_document_id');
        $ourIds = $ourRows->keys()->map(fn ($v) => (string) $v)->all();
        $this->info('Our documents: '.count($ourIds));

        $missingFromUs = array_diff($zohoIds, $ourIds);
        $missingFromZoho = array_diff($ourIds, $zohoIds);

        $presentInBoth = array_intersect($zohoIds, $ourIds);
        $staleEdits = [];
        foreach ($presentInBoth as $documentId) {
            $zohoTime = $zohoLastModified[$documentId] ?? null;
            $ourTime = $ourRows[$documentId] ?? null;
            if ($zohoTime === null) {
                continue;
            }
            try {
                $zohoParsed = Carbon::parse($zohoTime)->utc();
                $ourParsed = $ourTime !== null ? Carbon::parse($ourTime)->utc() : null;
                if ($ourParsed === null || $zohoParsed->gt($ourParsed)) {
                    $staleEdits[] = $documentId;
                }
            } catch (Throwable) {
                // Unparseable timestamp on either side — skip rather than guess.
            }
        }

        $skuByItemId = InventoryItem::query()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.zoho_item_id')) as zoho_item_id, sku")
            ->whereNotNull('extra')
            ->get()
            ->filter(fn ($row) => $row->zoho_item_id !== null)
            ->pluck('sku', 'zoho_item_id')
            ->all();

        $import = Import::create([
            'user_id' => $connection->user_id,
            'type' => $type.'_reconcile',
            'source' => 'zoho',
            'original_filename' => 'Zoho reconcile — '.now()->toDateTimeString(),
            'stored_path' => null,
            'source_signature' => 'zoho:'.$connection->id.':'.$type.'_reconcile:'.now()->timestamp,
            'status' => 'processing',
            'row_count' => 0,
        ]);
        $now = now();

        $refetch = function (string $documentId) use ($connection, $client, $mapper, $persister, $type, $zohoConfig, $transactionConfig, $skuByItemId, $import, $now): void {
            $detail = $client->fetchDetail($connection, $zohoConfig['endpoint'], $zohoConfig['detail_response_key'], $documentId);
            $rows = $mapper->mapToRows($detail, $type, $import->id, $skuByItemId, $now);
            if ($rows !== []) {
                $persister->persistIncremental($rows, $transactionConfig['table'], $transactionConfig['date_column']);
                $import->increment('row_count', count($rows));
            }
            if ($type === 'invoices') {
                $invoiceDocument = $mapper->mapToInvoiceDocument($detail);
                if ($invoiceDocument !== null) {
                    InvoiceDocument::query()->updateOrCreate(
                        ['zoho_invoice_id' => $invoiceDocument['zoho_invoice_id']],
                        $invoiceDocument,
                    );
                }
            }
        };

        $backfilled = 0;
        $skippedBackfills = 0;
        foreach ($missingFromUs as $documentId) {
            if ($throttle->tooManyAttempts($connection)) {
                $skippedBackfills = count($missingFromUs) - $backfilled;
                $this->warn("  budget ran out — {$skippedBackfills} new document(s) left for the next run");
                break;
            }
            try {
                $throttle->hit($connection);
                $refetch($documentId);
                $backfilled++;
                $this->line("  + backfilled {$documentId}");
            } catch (Throwable $e) {
                $this->warn("  failed to backfill {$documentId}: ".$e->getMessage());
            }
        }

        $caughtUp = 0;
        $skippedStaleEdits = 0;
        foreach ($staleEdits as $documentId) {
            if ($throttle->tooManyAttempts($connection)) {
                $skippedStaleEdits = count($staleEdits) - $caughtUp;
                $this->warn("  budget ran out — {$skippedStaleEdits} stale edit(s) left for the next run");
                break;
            }
            try {
                $throttle->hit($connection);
                $refetch($documentId);
                $caughtUp++;
                $this->line("  ~ caught up stale edit: {$documentId}");
            } catch (Throwable $e) {
                $this->warn("  failed to refresh {$documentId}: ".$e->getMessage());
            }
        }

        $import->update(['status' => 'completed', 'error_message' => null]);

        // Flag, never delete. Only touches rows not already flagged, so a
        // document missing across several sweeps keeps its ORIGINAL
        // missing-since date.
        $newlyFlagged = $missingFromZoho === [] ? 0 : DB::table($table)
            ->whereIn('zoho_document_id', $missingFromZoho)
            ->whereNull('zoho_missing_since')
            ->update(['zoho_missing_since' => $now]);

        $unflagged = DB::table($table)
            ->whereIn('zoho_document_id', $zohoIds)
            ->whereNotNull('zoho_missing_since')
            ->update(['zoho_missing_since' => null]);

        $this->info("Backfilled (new): {$backfilled}".($skippedBackfills > 0 ? " ({$skippedBackfills} skipped — budget, retried next run)" : ''));
        $this->info("Caught up (stale edit): {$caughtUp}".($skippedStaleEdits > 0 ? " ({$skippedStaleEdits} skipped — budget, retried next run)" : ''));
        $this->info('Newly flagged as missing from Zoho: '.$newlyFlagged);
        $this->info('Un-flagged (reappeared): '.$unflagged);

        return self::SUCCESS;
    }
}
