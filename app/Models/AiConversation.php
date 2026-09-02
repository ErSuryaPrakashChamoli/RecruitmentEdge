<?php

namespace App\Models;

use App\Enums\AiConversationStatus;
use Database\Factories\AiConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'context_type',
    'context_id',
    'title',
    'model',
    'status',
    'last_message_at',
])]
class AiConversation extends Model
{
    /** @use HasFactory<AiConversationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AiConversationStatus::class,
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        // Ordered by id, not created_at — see App\Services\AI\Orchestrator\ConversationContextBuilder
        // for why: same-second inserts make created_at ordering unstable.
        return $this->hasMany(AiMessage::class, 'conversation_id')->oldest('id');
    }
}
