<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerSalesRecommendation extends Model
{
    protected $table = 'customer_sales_recommendations';

    protected $fillable = [
        'zoho_customer_id',
        'customer_name',
        'sales_growth_segment',
        'buying_rhythm_segment',
        'aov_growth_segment',
        'sku_growth_segment',
        'portfolio_behavior_segment',
        'last_purchase_date',
        'days_since_last_purchase',
        'priority_level',
        'overall_sales_status',
        'primary_recommendation',
        'secondary_recommendation',
        'final_why',
        'recommended_sales_rep_action',
        'key_risk',
        'key_opportunity',
        'confidence',
        'total_revenue',
        'revenue_rank',
        'sales_rank',
    ];

    protected function casts(): array
    {
        return [
            'last_purchase_date' => 'date',
            'days_since_last_purchase' => 'integer',
            'total_revenue' => 'decimal:2',
            'revenue_rank' => 'integer',
            'sales_rank' => 'integer',
        ];
    }
}
