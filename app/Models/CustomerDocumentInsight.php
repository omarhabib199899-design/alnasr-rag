<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDocumentInsight extends Model
{
    protected $table = 'customer_document_insights';

    protected $fillable = [
        'zoho_customer_id',
        'customer_name',
        'rep_name',
        'action',
        'why',
    ];
}
