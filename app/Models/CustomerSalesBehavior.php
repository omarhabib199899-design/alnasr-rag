<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerSalesBehavior extends Model
{
    protected $table = 'customer_sales_behavior';

    protected $fillable = [
        'zoho_customer_id',
        'customer_name',
        'historical_first_purchase',
        'historical_last_purchase',
        'sales_growth_why',
        'buying_rhythm_why',
        'aov_growth_why',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'historical_first_purchase' => 'date',
            'historical_last_purchase' => 'date',
            'extra' => 'array',
        ];
    }
}
