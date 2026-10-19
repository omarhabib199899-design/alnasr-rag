<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'chat_conversation_id',
        'role',
        'answer_type',
        'content',
        'computed_spec',
        'citation_data',
    ];

    protected function casts(): array
    {
        return [
            'computed_spec' => 'array',
            'citation_data' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }
}
