<?php

namespace App\Services\Zoho;

use Illuminate\Support\Carbon;

/**
 * Maps one raw Zoho contact detail response to a customers row's attributes.
 * Extracted out of ZohoContactSyncJob (2026-10-05) so ReconcileCustomers can
 * reuse the exact same field mapping when backfilling a contact the hourly
 * live sync missed — one source of truth instead of two copies that can
 * silently drift apart.
 *
 * Deliberately only covers the fields confirmed against a real live contact
 * detail response (CUS-1526, 2026-10-05) — leaves out cr_status,
 * tax_registration_number, portal_enabled, sales_owner,
 * moh_sfda_license_expiry_date, same as that confirmation's own findings
 * (either absent from the sample or the live field didn't obviously
 * correspond — cf_moh_sfda_license there is a file attachment, not a date).
 */
class ZohoContactMapper
{
    /**
     * @param  array<string, mixed>  $detail  one raw Zoho contact detail object
     * @return array<string, mixed>
     */
    public function mapToCustomerAttributes(array $detail, Carbon $now): array
    {
        $customFields = $detail['custom_field_hash'] ?? [];

        return [
            'customer_name' => $detail['contact_name'] ?? null,
            'live_contact_data' => $detail,
            'live_contact_synced_at' => $now,
            'owner_name' => $detail['owner_name'] ?? null,
            'currency_code' => $detail['currency_code'] ?? null,
            'payment_terms_label' => $detail['payment_terms_label'] ?? null,
            'tax_treatment' => $detail['tax_treatment'] ?? null,
            'approval_status' => $customFields['cf_approval_status'] ?? null,
            'request_type' => $customFields['cf_request_type'] ?? null,
            'edit_summary_note' => $customFields['cf_edit_summary_note'] ?? null,
            'last_approved_snapshot' => $customFields['cf_last_approved_snapshot'] ?? null,
            'billing_address' => $detail['billing_address'] ?? null,
            'shipping_address' => $detail['shipping_address'] ?? null,
            'live_outstanding_balance' => $detail['outstanding_receivable_amount'] ?? null,
            'customer_number' => $detail['contact_number'] ?? null,
            // Zoho's live status is lowercase ("active") but the Excel-
            // imported rows (and the blade's `status === 'Active'`
            // badge-color check) use "Active" — normalized so both sources
            // agree.
            'status' => isset($detail['status']) && $detail['status'] !== ''
                ? ucfirst(strtolower($detail['status'])) : null,
            'display_name' => $detail['display_name'] ?? $detail['contact_name'] ?? null,
            'company_name' => $detail['company_name'] ?? null,
            'first_name' => $detail['first_name'] ?? null,
            'last_name' => $detail['last_name'] ?? null,
            'credit_limit' => isset($detail['credit_limit']) ? (float) $detail['credit_limit'] : null,
            'opening_balance' => isset($detail['opening_balance_amount']) ? (float) $detail['opening_balance_amount'] : null,
            'cr_no' => $customFields['cf_cr_no'] ?? null,
            'cr_expiry_date' => $customFields['cf_cr_expiry_date_unformatted'] ?? null,
            'gln_no' => $customFields['cf_gln_no'] ?? null,
            'attested_account' => $customFields['cf_attested_account'] ?? null,
            // Added 2026-10-15 — confirmed live against CUS-1517's real
            // contact detail response. Zoho's own "custom_fields" list names
            // this "Finance Dept Last Review Date" with a clean field key
            // (cf_last_review_date); never previously captured.
            'finance_dept_last_review_date' => $customFields['cf_last_review_date_unformatted'] ?? null,
            // Added 2026-10-15 — same contact's "Last Reconciliation date"
            // (matched by Zoho's own human-readable label in the
            // "custom_fields" array, not by key name) lives under an opaque,
            // Zoho-auto-generated field key (cf_3fea20) rather than
            // something semantic — this org renamed the field after Zoho
            // had already assigned its internal id. Confirmed against this
            // exact connection's live response; re-verify if it ever reads
            // back empty across the board (would mean the opaque id shifted).
            'last_reconciliation_date' => $customFields['cf_3fea20_unformatted'] ?? null,
            'rep_name' => $customFields['cf_rep_name'] ?? null,
            // Added 2026-10-09 — confirmed live against a real contact
            // (CUS-1412) that Zoho's custom_field_hash does carry this under
            // cf_sales_owner (trailing space observed on the raw value,
            // e.g. "AHMED SABRY ", hence trim() — rep_name above doesn't
            // need it, no trailing space seen there). The earlier
            // ZohoContactMapper build (2026-09-22-ish, see this class's own
            // docblock) concluded this field was "absent from the sample or
            // didn't obviously correspond" and left it out entirely —
            // sales_owner has instead only ever come from the one-time
            // ImportCustomersAnalyticsReport Excel import since, so only 50
            // of 1,490 customers had any value until this fix.
            'sales_owner' => isset($customFields['cf_sales_owner']) ? trim((string) $customFields['cf_sales_owner']) : null,
            // Added 2026-10-14 — same story as sales_owner above: a real
            // Zoho field (cf_data_entry) this mapper never read, confirmed
            // live against CUS-1517 ("Walid Metwaly", matching the
            // manager's own Customers Excel export exactly).
            'data_entry' => isset($customFields['cf_data_entry']) ? trim((string) $customFields['cf_data_entry']) : null,
            'account_type' => $customFields['cf_account_type'] ?? null,
            'city' => $customFields['cf_city'] ?? null,
            'warehouse_city' => $customFields['cf_wh_city'] ?? null,
            // ->utc() is load-bearing, not cosmetic — same fix
            // SyncZohoTransactionTypeJob's watermark commit already needed
            // (2026-09-28): Zoho's timestamps carry a +0300 offset, and
            // Eloquent's 'datetime' cast writes whatever timezone the Carbon
            // instance is CURRENTLY in when saving, it does not itself
            // convert to the app's UTC timezone first. Without this,
            // zoho_last_modified_time silently lands ~3 hours ahead of
            // reality on every write. Confirmed live 2026-10-05: this exact
            // omission (introduced a few hours earlier the same day, when
            // this field was first added here) made ReconcileCustomers'
            // fresh-vs-stored comparison flag 291 of 1,482 customers as a
            // "stale edit" that didn't really happen — every customer the
            // hourly sync had touched since the omission went in now
            // compared as "older" against its own correct live value.
            'zoho_created_time' => isset($detail['created_time']) ? Carbon::parse($detail['created_time'])->utc() : null,
            'zoho_last_modified_time' => isset($detail['last_modified_time']) ? Carbon::parse($detail['last_modified_time'])->utc() : null,
            // Cleared on every successful map — ReconcileCustomers is the
            // only thing that ever sets this, and a contact mapped here was
            // just confirmed present in Zoho, so any prior "missing" flag no
            // longer applies.
            'zoho_missing_since' => null,
        ];
    }
}
