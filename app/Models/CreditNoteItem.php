<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteItem extends Model
{
    protected $fillable = [
        'zoho_document_id',
        'document_number',
        'customer_name',
        'sku',
        'warehouse',
        'quantity',
        'transaction_date',
        'status',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'transaction_date' => 'date',
            'extra' => 'array',
        ];
    }
}
