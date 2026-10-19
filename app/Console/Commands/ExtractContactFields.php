<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * Populates customers.owner_name/currency_code/payment_terms_label/
 * tax_treatment/approval_status/billing_address/shipping_address/
 * live_outstanding_balance from already-synced live_contact_data — pure
 * local JSON parsing, no Zoho API calls. Re-runnable: safe to run again
 * after ZohoContactSyncJob refreshes more contacts, or if extraction logic
 * changes.
 *
 * Field names confirmed against a real live Zoho contact detail response
 * (2026-09-22) rather than guessed — see live_contact_data's own migration
 * and the extracted-fields migration for how each column maps back to the
 * raw payload.
 */
class ExtractContactFields extends Command
{
    protected $signature = 'reports:extract-contact-fields';

    protected $description = 'Populate precise customer columns (address, owner, currency, tax treatment, approval status) from already-synced live_contact_data';

    public function handle(): int
    {
        $updated = 0;

        Customer::query()
            ->whereNotNull('live_contact_data')
            ->chunkById(200, function ($customers) use (&$updated) {
                foreach ($customers as $customer) {
                    $data = $customer->live_contact_data;

                    if (! is_array($data)) {
                        continue;
                    }

                    $customFields = $data['custom_field_hash'] ?? [];

                    $customer->forceFill([
                        'owner_name' => $data['owner_name'] ?? null,
                        'currency_code' => $data['currency_code'] ?? null,
                        'payment_terms_label' => $data['payment_terms_label'] ?? null,
                        'tax_treatment' => $data['tax_treatment'] ?? null,
                        'approval_status' => $customFields['cf_approval_status'] ?? null,
                        'request_type' => $customFields['cf_request_type'] ?? null,
                        'edit_summary_note' => $customFields['cf_edit_summary_note'] ?? null,
                        'last_approved_snapshot' => $customFields['cf_last_approved_snapshot'] ?? null,
                        'billing_address' => $data['billing_address'] ?? null,
                        'shipping_address' => $data['shipping_address'] ?? null,
                        'live_outstanding_balance' => $data['outstanding_receivable_amount'] ?? null,
                    ])->save();

                    $updated++;
                }
            });

        $this->info("Done — {$updated} customers updated from live_contact_data.");

        return self::SUCCESS;
    }
}
