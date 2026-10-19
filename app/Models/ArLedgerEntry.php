<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArLedgerEntry extends Model
{
    protected $table = 'ar_ledger_entries';

    protected $fillable = [
        'transaction_date',
        'transaction_number',
        'entity_type',
        'category',
        'zoho_entity_id',
        'zoho_transaction_id',
        'zoho_customer_id',
        'customer_number',
        'customer_name',
        'ar_account_name',
        'account_type',
        'reference_no',
        'debit_or_credit',
        'statement_amount',
        'balance',
        'inv_balance_due_date',
        'sales_exc_vat',
        'cogs',
        'gross_profit',
        'gp_percent',
        'kpi_amount',
        'balance_aging',
    ];
}
