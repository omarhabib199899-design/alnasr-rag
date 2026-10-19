<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryWarehouseStock extends Model
{
    protected $table = 'inventory_warehouse_stock';

    protected $fillable = [
        'sku',
        'warehouse',
        'item_name',
        'closing_stock',
    ];

    protected function casts(): array
    {
        return [
            'closing_stock' => 'integer',
        ];
    }
}
