<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemOwner extends Model
{
    protected $fillable = [
        'zoho_item_id',
        'sku',
        'item_name',
        'owner',
    ];
}
