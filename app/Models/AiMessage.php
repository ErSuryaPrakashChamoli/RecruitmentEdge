<?php

namespace App\Models;

use App\Enums\AiMessageRole;
use Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'conversation_id',
    'role',
    'content',
    'tool_calls',
    'tool_call_id',
    'tool_name',
    'input_tokens',
    'output_tokens',
    'cached_tokens',
    'cost',
])]
class AiMessage extends Model
{
    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => AiMessageRole::class,
            'tool_calls' => 'array',
            'cost' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /**
     * @return HasMany<AiToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiToolCall::class, 'message_id');
    }
}
