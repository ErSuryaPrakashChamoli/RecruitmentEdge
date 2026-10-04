<?php

namespace App\Models;

use App\Enums\AiConversationStatus;
use App\Models\Concerns\BelongsToTenant;
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
    use BelongsToTenant, HasFactory;

    /**
     * The AI privacy boundary new conversations are created under (Phase 8.1). Conversations
     * without one predate it: they remain viewable, but are read-only and never rewritten.
     */
    public const int PRIVACY_VERSION = 1;

    protected static function booted(): void
    {
        static::creating(function (AiConversation $conversation): void {
            $conversation->privacy_version ??= self::PRIVACY_VERSION;
        });
    }

    public function isLegacy(): bool
    {
        return $this->privacy_version === null;
    }

    protected function casts(): array
    {
        return [
            'status' => AiConversationStatus::class,
            'last_message_at' => 'datetime',
            'privacy_version' => 'integer',
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
