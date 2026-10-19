<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesItemMonthlyPerformance extends Model
{
    protected $table = 'sales_item_monthly_performance';

    protected $fillable = [
        'year',
        'month',
        'zoho_customer_id',
        'zoho_item_id',
        'item_name',
        'zoho_location_id',
        'qty',
        'discount_amount',
        'cogs',
        'item_total',
        'item_gross_profit',
        'discount_percent',
    ];
}
