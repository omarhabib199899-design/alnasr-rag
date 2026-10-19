<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\ZohoConnection;
use App\Models\ZohoSyncCursor;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoInventoryClient;
use App\Services\Zoho\ZohoOAuthClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs Zoho's real Customer Payments module (GET /inventory/v1/
 * customerpayments) straight into customer_payments — replaces
 * ar_ledger_entries (entity_type='customer_payment', sourced from the Zoho
 * Analytics "AR Ledger Master" view) as the Payments Received screen's
 * data source, per the manager's explicit direction (2026-10-14): stop
 * depending on that Analytics view for anything, because it can change
 * under us and we don't control its logic — build this data ourselves
 * straight from Zoho's own primary API instead.
 *
 * Needs the ZohoInventory.customerpayments.READ scope (added to
 * ZohoOAuthClient::SCOPE the same day) — a fresh /zoho/connect is required
 * before this can run; the existing token has no permission for this
 * endpoint (confirmed live: 401, code 57, before the scope was added).
 *
 * Modeled on SyncZohoTransactionTypeJob's chunked, self-redispatching,
 * cursor-resumable design (same production lessons: tries=1 with a manual
 * retry-and-redispatch on any exception so a transient Zoho error can't
 * silently kill the chain; a conservative per-run document cap to stay
 * under the queue worker's job timeout) and its created_time-ascending
 * initial-backfill fix (2026-10-14, see that job's own docblock for the
 * full reasoning) from day one — this is a brand-new cursor, so it starts
 * in backfill mode and is never exposed to the page-drift bug that fix
 * addresses elsewhere.
 *
 * Unlike invoices/sales orders, Zoho's customerpayments LIST response is
 * NOT missing the fields this table needs (confirmed against this org's
 * own API docs — payments are a flat record, not a document with line
 * items) — no per-payment detail fetch required, one API call per page of
 * up to 200 payments instead of one call per payment.
 */
class CustomerPaymentSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const PER_PAGE = 200;

    private const MAX_PAGES_PER_RUN = 3;

    private const THROTTLE_RETRY_DELAY_MINUTES = 10;

    private const WATERMARK_SAFETY_BUFFER_MINUTES = 15;

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly ?string $cursorKey = null,
        public readonly ?int $importId = null,
    ) {}

    public function handle(
        ZohoOAuthClient $oauth,
        ZohoInventoryClient $inventoryClient,
        ZohoApiThrottle $throttle,
    ): void {
        $cursorKey = $this->cursorKey ?? 'customer_payments';
        $organizationId = $inventoryClient->resolveOrganizationId($this->zohoConnection);

        // Tracked via the same Import row mechanism SyncZohoTransactionTypeJob
        // uses — ZohoSyncService::alreadyRunning() checks for a 'processing'
        // Import row with this type before dispatching a new chain each hour;
        // without this, that guard would silently do nothing (no row to find)
        // and every hourly tick would start a second, concurrent chain on top
        // of whichever one is still mid-flight.
        $import = $this->importId
            ? Import::findOrFail($this->importId)
            : Import::create([
                'user_id' => $this->zohoConnection->user_id,
                'type' => $cursorKey,
                'source' => 'zoho',
                'original_filename' => 'Zoho sync — '.now()->toDateTimeString(),
                'stored_path' => null,
                'source_signature' => 'zoho:'.$this->zohoConnection->id.':'.$cursorKey,
                'status' => 'processing',
                'row_count' => 0,
            ]);

        if ($throttle->tooManyAttempts($this->zohoConnection)) {
            $import->touch();
            self::dispatch($this->zohoConnection, $cursorKey, $import->id)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        $cursor = ZohoSyncCursor::firstOrCreate(
            ['zoho_organization_id' => $organizationId, 'type' => $cursorKey],
            ['zoho_connection_id' => $this->zohoConnection->id, 'next_page' => 1, 'next_index' => 0],
        );

        $page = max(1, $cursor->next_page ?? 1);
        $pendingWatermark = $cursor->pending_watermark;
        $isInitialBackfill = $cursor->last_modified_watermark === null;
        $sortColumn = $isInitialBackfill ? 'created_time' : 'last_modified_time';
        $sortOrder = $isInitialBackfill ? 'A' : 'D';

        $pagesThisRun = 0;
        $reachedWatermark = false;
        $isComplete = false;

        try {
            $oauth->refreshIfNeeded($this->zohoConnection);

            while ($pagesThisRun < self::MAX_PAGES_PER_RUN) {
                if ($throttle->tooManyAttempts($this->zohoConnection)) {
                    break;
                }

                $throttle->hit($this->zohoConnection);
                $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$this->zohoConnection->access_token])
                    ->timeout(30)->retry(3, 1000)
                    ->get($this->zohoConnection->api_domain.'/inventory/v1/customerpayments', [
                        'organization_id' => $organizationId,
                        'page' => $page,
                        'per_page' => self::PER_PAGE,
                        'sort_column' => $sortColumn,
                        'sort_order' => $sortOrder,
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('Zoho customerpayments fetch failed on page '.$page.': '.$response->body());
                }

                $payments = $response->json('customerpayments') ?? [];
                $hasMore = (bool) ($response->json('page_context.has_more_page') ?? (count($payments) >= self::PER_PAGE));

                foreach ($payments as $payment) {
                    $modifiedTime = $payment['last_modified_time'] ?? null;

                    if ($isInitialBackfill) {
                        if ($modifiedTime !== null && ($pendingWatermark === null || Carbon::parse($modifiedTime)->gt(Carbon::parse($pendingWatermark)))) {
                            $pendingWatermark = $modifiedTime;
                        }
                    } elseif ($pendingWatermark === null) {
                        $pendingWatermark = $modifiedTime;
                    }

                    if (! $isInitialBackfill && $modifiedTime !== null && Carbon::parse($modifiedTime)->lessThanOrEqualTo($cursor->last_modified_watermark)) {
                        $reachedWatermark = true;
                        break 2;
                    }

                    $this->upsertPayment($payment);
                    $import->increment('row_count');
                }

                $page++;
                $pagesThisRun++;

                if (! $hasMore) {
                    $isComplete = true;
                    break;
                }
            }
        } catch (Throwable $e) {
            Log::warning("CustomerPaymentSyncJob [{$cursorKey}] failed on page {$page}, retrying in ".self::THROTTLE_RETRY_DELAY_MINUTES.'m: '.$e->getMessage());
            $import->touch();
            self::dispatch($this->zohoConnection, $cursorKey, $import->id)
                ->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        if ($reachedWatermark) {
            $isComplete = true;
        }

        if (! $isComplete) {
            $cursor->update(['next_page' => $page, 'next_index' => 0, 'pending_watermark' => $pendingWatermark]);
            $import->touch();
            self::dispatch($this->zohoConnection, $cursorKey, $import->id);

            return;
        }

        $cursor->update([
            'next_page' => 1,
            'next_index' => 0,
            'pending_watermark' => null,
            'last_modified_watermark' => $pendingWatermark !== null ? Carbon::parse($pendingWatermark)->utc()->subMinutes(self::WATERMARK_SAFETY_BUFFER_MINUTES) : $cursor->last_modified_watermark,
        ]);
        $import->update(['status' => 'completed', 'error_message' => null]);
    }

    private function upsertPayment(array $payment): void
    {
        $zohoPaymentId = (string) ($payment['payment_id'] ?? '');
        if ($zohoPaymentId === '') {
            return;
        }

        // customer_number intentionally NOT set here — it's a customers.extra
        // custom field keyed by zoho_customer_id, already reliably synced via
        // ZohoContactSyncJob; the controller joins to the customers table for
        // it instead of guessing which (if any) field name the payments
        // endpoint itself might echo it under.
        DB::table('customer_payments')->updateOrInsert(
            ['zoho_payment_id' => $zohoPaymentId],
            [
                'payment_number' => $payment['payment_number'] ?? null,
                'payment_date' => $payment['date'] ?? null,
                'txn_posting_date' => $payment['txn_posting_date'] ?: null,
                'zoho_customer_id' => isset($payment['customer_id']) ? (string) $payment['customer_id'] : null,
                'customer_name' => $payment['customer_name'] ?? null,
                'amount' => $payment['amount'] ?? null,
                'bcy_amount' => $payment['bcy_amount'] ?? null,
                'unused_amount' => $payment['unused_amount'] ?? null,
                'bcy_unused_amount' => $payment['bcy_unused_amount'] ?? null,
                'payment_mode' => $payment['payment_mode'] ?? null,
                'reference_number' => $payment['reference_number'] ?? null,
                'invoice_numbers' => $payment['invoice_numbers'] ?: null,
                'account_id' => $payment['account_id'] ?? null,
                'account_name' => $payment['account_name'] ?? null,
                'description' => $payment['description'] ?? null,
                'last_four_digits' => $payment['last_four_digits'] ?? null,
                'gateway_transaction_id' => $payment['gateway_transaction_id'] ?? null,
                'bcy_refunded_amount' => $payment['bcy_refunded_amount'] ?? null,
                'has_attachment' => isset($payment['has_attachment']) ? (int) (bool) $payment['has_attachment'] : null,
                'documents' => is_array($payment['documents'] ?? null) ? json_encode($payment['documents']) : ($payment['documents'] ?? null),
                'tax_account_id' => $payment['tax_account_id'] ?? null,
                'tax_account_name' => $payment['tax_account_name'] ?? null,
                'tax_amount_withheld' => $payment['tax_amount_withheld'] ?? null,
                'currency_code' => $payment['currency_code'] ?? null,
                'payment_method' => $payment['payment_method'] ?? null,
                'location_name' => $payment['location_name'] ?? null,
                'zoho_created_time' => $payment['created_time'] ?? null,
                'zoho_last_modified_time' => $payment['last_modified_time'] ?? null,
                'extra' => json_encode($payment),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
