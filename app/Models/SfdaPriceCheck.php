<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SfdaPriceCheck extends Model
{
    protected $table = 'sfda_price_checks';

    protected $fillable = [
        'sku',
        'item_name',
        'sfda_reg_no',
        'our_price',
        'official_price',
        'price_difference',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'our_price' => 'decimal:2',
            'official_price' => 'decimal:2',
            'price_difference' => 'decimal:2',
            'checked_at' => 'datetime',
        ];
    }
}
