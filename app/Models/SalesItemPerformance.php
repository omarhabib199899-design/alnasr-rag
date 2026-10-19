<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesItemPerformance extends Model
{
    protected $table = 'sales_item_performance';

    protected $fillable = [
        'zoho_transaction_id',
        'transaction_number',
        'transaction_type',
        'transaction_date',
        'zoho_customer_id',
        'zoho_item_id',
        'item_name',
        'zoho_location_id',
        'qty',
        'batch',
        'expiry',
        'item_price',
        'discount_amount',
        'cogs',
        'item_total',
        'item_gross_profit',
        'discount_percent',
    ];
}
