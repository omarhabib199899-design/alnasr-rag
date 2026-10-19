<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoSyncCursor extends Model
{
    protected $fillable = [
        'zoho_connection_id',
        'zoho_organization_id',
        'type',
        'last_modified_watermark',
        'pending_watermark',
        'next_page',
        'next_index',
    ];

    protected function casts(): array
    {
        return [
            'last_modified_watermark' => 'datetime',
            'pending_watermark' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ZohoConnection::class, 'zoho_connection_id');
    }
}
