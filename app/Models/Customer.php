<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'zoho_contact_id',
        'customer_name',
        'display_name',
        'status',
        'company_name',
        'first_name',
        'last_name',
        'customer_number',
        'city',
        'rep_name',
        'sales_owner',
        'data_entry',
        'account_type',
        'credit_limit',
        'accounts_receivable',
        'payments_due',
        'opening_balance',
        'cr_no',
        'cr_status',
        'cr_expiry_date',
        'tax_registration_number',
        'gln_no',
        'moh_sfda_license_expiry_date',
        'reconciliation_status',
        'last_reconciliation_date',
        'finance_dept_last_review_date',
        'attested_account',
        'portal_enabled',
        'warehouse_city',
        'zoho_created_time',
        'zoho_last_modified_time',
        'extra',
        'live_contact_data',
        'live_contact_synced_at',
        'zoho_missing_since',
        'owner_name',
        'currency_code',
        'payment_terms_label',
        'tax_treatment',
        'approval_status',
        'request_type',
        'edit_summary_note',
        'last_approved_snapshot',
        'billing_address',
        'shipping_address',
        'live_outstanding_balance',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'cr_expiry_date' => 'date',
            'moh_sfda_license_expiry_date' => 'date',
            'last_reconciliation_date' => 'date',
            'finance_dept_last_review_date' => 'date',
            'zoho_created_time' => 'datetime',
            'zoho_last_modified_time' => 'datetime',
            'extra' => 'array',
            'live_contact_data' => 'array',
            'live_contact_synced_at' => 'datetime',
            'zoho_missing_since' => 'datetime',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'live_outstanding_balance' => 'decimal:2',
        ];
    }
}
