<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    /** @see SaleItem::booted() — same reasoning, applied consistently to every transaction-items model. */
    protected static function booted(): void
    {
        static::addGlobalScope('excludeZohoMissing', function ($query) {
            $query->whereNull('zoho_missing_since');
        });
    }

    protected $fillable = [
        'import_id',
        'zoho_document_id',
        'zoho_line_item_id',
        'document_number',
        'customer_name',
        'warehouse',
        'sku',
        'quantity',
        'invoice_date',
        'status',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'invoice_date' => 'date',
            'extra' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
