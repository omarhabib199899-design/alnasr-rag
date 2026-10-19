<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCreditUsage extends Model
{
    protected $table = 'customer_credit_usage';

    protected $fillable = [
        'zoho_customer_id',
        'customer_number',
        'customer_name',
        'management',
        'rep_name',
        'credit_limit',
        'used_credit',
        'credit_utilization_pct',
        'remaining_credit',
        'available_credit_pct',
        'credit_status',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'used_credit' => 'decimal:2',
            'credit_utilization_pct' => 'decimal:2',
            'remaining_credit' => 'decimal:2',
            'available_credit_pct' => 'decimal:2',
        ];
    }
}
