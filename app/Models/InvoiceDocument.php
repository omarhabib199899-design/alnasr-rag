<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceDocument extends Model
{
    protected $fillable = [
        'zoho_invoice_id',
        'invoice_number',
        'zoho_customer_id',
        'invoice_date',
        'due_date',
        'invoice_status',
        'status',
        'final_status',
        'sub_total',
        'discount_amount',
        'adjustment',
        'total',
        'balance',
        'balance_fcy',
        'write_off_amount',
        'salesperson_id',
        'location_id',
        'purchase_order_number',
        'age_in_days',
        'age_tier',
        'zoho_created_time',
        'zoho_last_modified_time',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sub_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'adjustment' => 'decimal:2',
            'total' => 'decimal:2',
            'balance' => 'decimal:2',
            'balance_fcy' => 'decimal:2',
            'write_off_amount' => 'decimal:2',
            'age_in_days' => 'integer',
            'zoho_created_time' => 'datetime',
            'zoho_last_modified_time' => 'datetime',
            'extra' => 'array',
        ];
    }
}
