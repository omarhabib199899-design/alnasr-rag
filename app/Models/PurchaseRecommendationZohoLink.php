<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRecommendationZohoLink extends Model
{
    protected $fillable = ['sku', 'zoho_record_id'];
}
