<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * Customer 360 URLs carry the Zoho customer id — the id every Analytics table uses — so a customer that is in
     * Zoho Analytics but not (yet) in this table still opens. Links by our own id (short numbers) keep working.
     */
    public function getRouteKeyName()
    {
        return 'zoho_contact_id';
    }

    public function getRouteKey()
    {
        return $this->zoho_contact_id ?: $this->getKey();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $value = (string) $value;
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }
        if (! preg_match('/^\d{1,32}$/', $value)) {
            return null;
        }
        if (strlen($value) < 10) { // our own customers.id — Zoho ids are 16+ digits
            return static::find($value);
        }

        return static::where('zoho_contact_id', $value)->first() ?? static::fromAnalytics($value);
    }

    /** An unsaved Customer built from zoho_raw_customers, for a customer not in this table yet (nothing is written). */
    private static function fromAnalytics(string $zohoId): ?self
    {
        $row = DB::table('zoho_raw_customers')->where('customer_id', $zohoId)->first();
        if ($row === null) {
            return null;
        }

        return (new static)->forceFill([
            'zoho_contact_id' => $zohoId, 'customer_name' => $row->customer_name, 'customer_number' => $row->customer_number,
            'status' => $row->status, 'rep_name' => $row->rep_name, 'sales_owner' => $row->sales_owner, 'data_entry' => $row->data_entry,
            'account_type' => $row->account_type,
        ]);
    }
}
