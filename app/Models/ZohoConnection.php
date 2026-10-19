<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoConnection extends Model
{
    protected $fillable = [
        'user_id',
        'zoho_organization_id',
        'currency_code',
        'currency_symbol',
        'api_domain',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'status',
        'last_error',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
