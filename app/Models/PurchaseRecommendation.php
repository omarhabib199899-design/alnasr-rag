<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRecommendation extends Model
{
    protected $fillable = [
        'sku',
        'item_name',
        'warehouse',
        'top_warehouse_name',
        'top_warehouse_stock',
        'warehouse_count',
        'current_stock',
        'sold_6mo',
        'stockout_count_1yr',
        'customer_count_6mo',
        'top_customer_order_qty',
        'top_customer_name',
        'top_pharmacy_order_qty',
        'top_pharmacy_name',
        'expired_quantity',
        'pending_po',
        'total_available',
        'suggested_purchase',
        'stock_status',
        'price',
        'cost',
        'estimated_profit_6mo',
        'best_discount_vendor',
        'best_discount_price',
        'lowest_price_vendor',
        'lowest_price_value',
        'most_purchased_vendor',
        'most_purchased_price',
        'value',
        'zoho_pushed_hash',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'integer',
            'top_warehouse_stock' => 'integer',
            'warehouse_count' => 'integer',
            'sold_6mo' => 'integer',
            'stockout_count_1yr' => 'integer',
            'customer_count_6mo' => 'integer',
            'top_customer_order_qty' => 'integer',
            'top_pharmacy_order_qty' => 'integer',
            'expired_quantity' => 'integer',
            'pending_po' => 'integer',
            'total_available' => 'integer',
            'suggested_purchase' => 'integer',
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'estimated_profit_6mo' => 'decimal:2',
            'best_discount_price' => 'decimal:2',
            'lowest_price_value' => 'decimal:2',
            'most_purchased_price' => 'decimal:2',
            'value' => 'decimal:2',
        ];
    }
}
