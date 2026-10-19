<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\CustomerChangeLog;
use App\Models\Import;
use App\Models\ZohoConnection;
use App\Models\ZohoSyncCursor;
use App\Services\Zoho\ZohoApiThrottle;
use App\Services\Zoho\ZohoContactClient;
use App\Services\Zoho\ZohoContactMapper;
use App\Services\Zoho\ZohoInventoryClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs live Zoho contacts a few at a time, same self-dispatching chunked
 * design as SyncZohoTransactionTypeJob (this host kills long-running PHP
 * processes — see that class's docblock) and reusing the exact same
 * ZohoSyncCursor mechanism (type='contacts', keyed by zoho_organization_id)
 * — no new migration needed there, that table is already generic on
 * (zoho_organization_id, type).
 *
 * Deliberately does NOT try to parse the detail response into columns yet.
 * It stores the complete raw contact object into customers.live_contact_data
 * untouched, matching this project's standing practice of confirming real
 * field names against a live response (see live_contact_data's migration)
 * before writing precise extraction — the address/owner/currency/tax
 * treatment/approval-status columns are a fast-follow once this has run and
 * the real shape is visible.
 *
 * Upserts by zoho_contact_id, so a contact from this sync coexists with the
 * same row the Zoho Analytics import (ImportCustomersAnalyticsReport)
 * already created — this only adds/refreshes live_contact_data and
 * live_contact_synced_at on the existing row, it doesn't touch the
 * Analytics-sourced columns.
 */
class ZohoContactSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const MAX_DOCUMENTS_PER_RUN = 15;

    private const THROTTLE_RETRY_DELAY_MINUTES = 10;

    /**
     * Subtracted from the newest contact's timestamp before committing it as
     * the watermark — same fix as SyncZohoTransactionTypeJob's, applied here
     * too since this job shares the identical page/index cursor design and
     * the same vulnerability: a burst of records touched within a tight
     * window can shift pages between this chain's separate HTTP calls,
     * silently skipping whatever moved past an already-visited page.
     */
    private const WATERMARK_SAFETY_BUFFER_MINUTES = 15;

    /**
     * field => human label, for customer_change_logs — the same set of
     * columns the customer detail page actually shows, not every volatile
     * field in the raw live_contact_data payload (that would be mostly
     * noise: Zoho touches fields like last_reminder_sent_date on its own).
     * Requested by the manager after asking whether the system can show a
     * per-customer change history the way Zoho's own activity log does.
     */
    private const TRACKED_FIELDS = [
        'customer_name' => 'Customer Name',
        'owner_name' => 'Owner',
        'currency_code' => 'Currency',
        'payment_terms_label' => 'Payment Terms',
        'tax_treatment' => 'Tax Treatment',
        'approval_status' => 'Approval Status',
        'request_type' => 'Request Type',
        'edit_summary_note' => 'Edit Summary Note',
        'live_outstanding_balance' => 'Outstanding Balance',
    ];

    private const TRACKED_JSON_FIELDS = [
        'billing_address' => 'Billing Address',
        'shipping_address' => 'Shipping Address',
    ];

    public function __construct(
        public readonly ZohoConnection $zohoConnection,
        public readonly ?int $importId = null,
    ) {}

    public function handle(
        ZohoContactClient $contactClient,
        ZohoContactMapper $contactMapper,
        ZohoApiThrottle $throttle,
        ZohoInventoryClient $inventoryClient,
    ): void {
        $organizationId = $inventoryClient->resolveOrganizationId($this->zohoConnection);

        $import = $this->importId
            ? Import::findOrFail($this->importId)
            : Import::create([
                'user_id' => $this->zohoConnection->user_id,
                'type' => 'contacts',
                'source' => 'zoho',
                'original_filename' => 'Zoho contacts sync — '.now()->toDateTimeString(),
                'stored_path' => null,
                'source_signature' => 'zoho:'.$this->zohoConnection->id.':contacts',
                'status' => 'processing',
                'row_count' => 0,
            ]);

        if ($throttle->tooManyAttempts($this->zohoConnection)) {
            $import->touch();
            self::dispatch($this->zohoConnection, $import->id)->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        $cursor = ZohoSyncCursor::firstOrCreate(
            ['zoho_organization_id' => $organizationId, 'type' => 'contacts'],
            ['zoho_connection_id' => $this->zohoConnection->id, 'next_page' => 1, 'next_index' => 0],
        );

        $page = max(1, $cursor->next_page ?? 1);
        $index = $cursor->next_index ?? 0;
        $pendingWatermark = $cursor->pending_watermark;
        $documentsThisRun = 0;
        $reachedWatermark = false;
        $budgetExhausted = false;
        $isComplete = false;

        // Server-side filter, not just sort (2026-10-17) — confirmed live
        // this exact job had silently left CUS-1512/CUS-1513's Data Entry
        // assignment stale for days despite running hourly: the same page-
        // shift skip SyncZohoTransactionTypeJob's docblock documents for
        // transactions applies identically here (sort-only, no filter), and
        // ReconcileCustomers' own 4-hour safety net wasn't enough to close
        // the gap before it was noticed. See ZohoTransactionClient::
        // fetchPage()'s docblock for the filter's own verification.
        $modifiedSinceFilter = $cursor->last_modified_watermark !== null
            ? Carbon::parse($cursor->last_modified_watermark)->utc()->format('Y-m-d\TH:i:sO')
            : null;

        try {
            $throttle->hit($this->zohoConnection);
            $result = $contactClient->fetchPage($this->zohoConnection, $page, $modifiedSinceFilter);
            $pageContacts = $result['contacts'];
            $hasMorePages = $result['has_more'];

            $now = now();
            $updated = 0;

            while ($index < count($pageContacts) && $documentsThisRun < self::MAX_DOCUMENTS_PER_RUN) {
                if ($throttle->tooManyAttempts($this->zohoConnection)) {
                    $budgetExhausted = true;
                    break;
                }

                $summary = $pageContacts[$index];

                if ($pendingWatermark === null) {
                    $pendingWatermark = $summary['last_modified_time'] ?? null;
                }

                if ($cursor->last_modified_watermark !== null) {
                    $modifiedTime = $summary['last_modified_time'] ?? null;
                    if ($modifiedTime !== null && Carbon::parse($modifiedTime)->lessThanOrEqualTo($cursor->last_modified_watermark)) {
                        $reachedWatermark = true;
                        break;
                    }
                }

                $contactId = (string) ($summary['contact_id'] ?? '');
                if ($contactId !== '') {
                    $throttle->hit($this->zohoConnection);
                    $detail = $contactClient->fetchDetail($this->zohoConnection, $contactId);

                    // Passed as a plain array, not json_encode()'d here —
                    // Customer::$casts already marks live_contact_data as
                    // 'array', so Eloquent encodes it once on save.
                    // Confirmed live (2026-09-22): pre-encoding it here
                    // caused DOUBLE encoding (the cast then encoded the
                    // already-JSON string again), storing an escaped string
                    // instead of a queryable JSON object.
                    //
                    // Field mapping lives in ZohoContactMapper (extracted
                    // 2026-10-05) — shared with ReconcileCustomers, which
                    // reuses it verbatim to backfill any contact this hourly
                    // job ever misses (page-shift skip — see that command's
                    // docblock), so the two never drift apart.
                    $attributes = $contactMapper->mapToCustomerAttributes($detail, $now);
                    $attributes['customer_name'] = $attributes['customer_name'] ?? $summary['contact_name'] ?? $contactId;
                    $attributes['updated_at'] = $now;
                    $customFields = $detail['custom_field_hash'] ?? [];

                    $existing = Customer::where('zoho_contact_id', $contactId)->first();

                    // Business Unit / Promissory Note / Sales & Collection
                    // targets — these previously only came from a one-time
                    // manual Excel import (ImportCustomersAnalyticsReport,
                    // 2026-09-22) and silently went stale after that.
                    // Confirmed live (2026-09-27): Zoho's own custom fields
                    // already carry all of these (cf_management,
                    // cf_promissory_note[_issue_date], cf_{month}_sales_tgt/
                    // cf_{month}_collection_tgt, cf_annual_sales_tgt/
                    // cf_annual_collection_tgt) — the "_unformatted" variant
                    // is the plain number, no "SAR"/comma parsing needed.
                    // Merged onto the existing extra (not replaced) so the
                    // one-time import's other fields (branches, pricelist_id,
                    // delivery_time, finance dept fields — not present in
                    // Zoho's contact custom fields) aren't wiped out.
                    $attributes['extra'] = $this->mergeLiveTargetFields($existing?->extra ?? [], $customFields);

                    if ($existing !== null) {
                        $this->logFieldChanges($existing, $attributes, $now);
                    }

                    Customer::query()->updateOrCreate(['zoho_contact_id' => $contactId], $attributes);
                    $updated++;
                }

                $index++;
                $documentsThisRun++;
            }

            if ($updated > 0) {
                $import->increment('row_count', $updated);
            }

            if ($reachedWatermark) {
                $isComplete = true;
            } elseif ($index >= count($pageContacts)) {
                if ($hasMorePages) {
                    $page++;
                    $index = 0;
                } else {
                    $isComplete = true;
                }
            }
        } catch (Throwable $e) {
            Log::warning("ZohoContactSyncJob failed on page {$page} index {$index}, retrying in ".self::THROTTLE_RETRY_DELAY_MINUTES.'m: '.$e->getMessage());
            $import->touch();
            self::dispatch($this->zohoConnection, $import->id)->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));

            return;
        }

        if (! $isComplete) {
            $cursor->update(['next_page' => $page, 'next_index' => $index, 'pending_watermark' => $pendingWatermark]);
            $import->touch();
            $next = self::dispatch($this->zohoConnection, $import->id);
            if ($budgetExhausted) {
                $next->delay(now()->addMinutes(self::THROTTLE_RETRY_DELAY_MINUTES));
            }

            return;
        }

        // Anchored to when THIS pass started ($import->created_at), not the
        // newest last_modified_time it happened to observe — same reasoning
        // as SyncZohoTransactionTypeJob's identical fix (2026-10-17): with
        // the server-side filter above, any contact touched DURING the pass
        // still has last_modified_time after the pass's start, so anchoring
        // there guarantees the very next pass's filter still catches it
        // regardless of which page it ended up shuffled onto. Only the
        // very first pass ever (cursor->last_modified_watermark started
        // null, so $modifiedSinceFilter was null too) has no prior
        // watermark to improve on — that one still uses the max-seen-value
        // anchor, same as before.
        $newWatermark = $modifiedSinceFilter !== null
            ? Carbon::parse($import->created_at)->utc()->subMinutes(self::WATERMARK_SAFETY_BUFFER_MINUTES)
            : ($pendingWatermark !== null ? Carbon::parse($pendingWatermark)->utc()->subMinutes(self::WATERMARK_SAFETY_BUFFER_MINUTES) : $cursor->last_modified_watermark);

        $cursor->update([
            'next_page' => 1,
            'next_index' => 0,
            'pending_watermark' => null,
            // ->utc() is load-bearing — see SyncZohoTransactionTypeJob's
            // identical fix (2026-09-28) for the full story: Zoho's
            // last_modified_time carries a +0300 offset, and Eloquent's
            // 'datetime' cast doesn't itself normalize a Carbon instance to
            // the app's UTC timezone before writing it to the naive
            // DATETIME column — without this, the watermark silently ends
            // up ~3 hours ahead of real UTC, treating recently-changed
            // contacts as already-seen.
            'last_modified_watermark' => $newWatermark,
        ]);
        $import->update(['status' => 'completed', 'error_message' => null]);
    }

    /**
     * @param  array<string, mixed>  $extra  existing customers.extra to merge onto
     * @param  array<string, mixed>  $customFields  the raw custom_field_hash
     * @return array<string, mixed>
     */
    private function mergeLiveTargetFields(array $extra, array $customFields): array
    {
        $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        $monthLabels = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

        if (isset($customFields['cf_management'])) {
            $extra['management'] = $customFields['cf_management'];
        }
        if (isset($customFields['cf_promissory_note'])) {
            $extra['promissory_note'] = $customFields['cf_promissory_note'];
        }
        if (isset($customFields['cf_promissory_note_issue_date'])) {
            $extra['promissory_note_issue_date'] = $customFields['cf_promissory_note_issue_date'];
        }
        if (isset($customFields['cf_annual_sales_tgt_unformatted'])) {
            $extra['annual_sales_tgt'] = (float) $customFields['cf_annual_sales_tgt_unformatted'];
        }
        if (isset($customFields['cf_annual_collection_tgt_unformatted'])) {
            $extra['annual_collection_tgt'] = (float) $customFields['cf_annual_collection_tgt_unformatted'];
        }

        foreach ($months as $i => $month) {
            $salesKey = "cf_{$month}_sales_tgt_unformatted";
            if (isset($customFields[$salesKey])) {
                $extra['monthly_sales_targets'][$monthLabels[$i]] = (float) $customFields[$salesKey];
            }

            // July's collection target has a Zoho-side naming quirk
            // (cf_jul_collection_tgt_1, not cf_jul_collection_tgt) —
            // confirmed live 2026-09-27 against a real populated contact.
            $collectionKey = $month === 'jul'
                ? (isset($customFields['cf_jul_collection_tgt_1_unformatted']) ? 'cf_jul_collection_tgt_1_unformatted' : 'cf_jul_collection_tgt_unformatted')
                : "cf_{$month}_collection_tgt_unformatted";
            if (isset($customFields[$collectionKey])) {
                $extra['monthly_collection_targets'][$monthLabels[$i]] = (float) $customFields[$collectionKey];
            }
        }

        return $extra;
    }

    /**
     * @param  array<string, mixed>  $newAttributes
     */
    private function logFieldChanges(Customer $existing, array $newAttributes, Carbon $now): void
    {
        foreach (self::TRACKED_FIELDS as $field => $label) {
            $old = $existing->{$field};
            $new = $newAttributes[$field] ?? null;

            if ($this->stringifyValue($old) === $this->stringifyValue($new)) {
                continue;
            }

            CustomerChangeLog::create([
                'customer_id' => $existing->id,
                'zoho_contact_id' => $existing->zoho_contact_id,
                'field' => $field,
                'old_value' => $this->stringifyValue($old),
                'new_value' => $this->stringifyValue($new),
                'changed_at' => $now,
            ]);
        }

        foreach (self::TRACKED_JSON_FIELDS as $field => $label) {
            $old = $existing->{$field};
            $new = $newAttributes[$field] ?? null;

            if (json_encode($old) === json_encode($new)) {
                continue;
            }

            CustomerChangeLog::create([
                'customer_id' => $existing->id,
                'zoho_contact_id' => $existing->zoho_contact_id,
                'field' => $field,
                'old_value' => $old !== null ? json_encode($old) : null,
                'new_value' => $new !== null ? json_encode($new) : null,
                'changed_at' => $now,
            ]);
        }
    }

    private function stringifyValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
