<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    /**
     * Excludes rows ReconcileTransactions has flagged as deleted in Zoho
     * (zoho_missing_since set) from every normal Eloquent query — same
     * reasoning as a SoftDeletes scope, applied manually since the column
     * means "Zoho deleted this", not "we deleted this". Safe to apply
     * globally here because nothing that needs to SEE flagged rows (the
     * sync job, the reconcile sweep itself) ever queries through this
     * model — both go through DB::table('sale_items') directly.
     */
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
        'sku',
        'quantity',
        'order_date',
        'status',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'order_date' => 'date',
            'extra' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
