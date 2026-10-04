<?php

namespace App\Models;

use App\Enums\AiRiskLevel;
use App\Enums\AiToolCallStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AiToolCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'message_id',
    'tool_name',
    'provider_call_id',
    'arguments',
    'provider_metadata',
    'risk_level',
    'status',
    'requires_confirmation',
    'approved_by',
    'approved_at',
    'executed_at',
    'requested_by',
    'expires_at',
    'authority_fingerprint',
])]
class AiToolCall extends Model
{
    /** @use HasFactory<AiToolCallFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'provider_metadata' => 'array',
            'risk_level' => AiRiskLevel::class,
            'status' => AiToolCallStatus::class,
            'requires_confirmation' => 'boolean',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    /**
     * Phase 8.4: who asked for this action (immutable). Only they may approve it.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Phase 8.4: a pending action past its approval window can never run.
     */
    public function isExpired(): bool
    {
        return $this->requires_confirmation && ($this->expires_at === null || $this->expires_at->isPast());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasOne<AiToolResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(AiToolResult::class, 'tool_call_id');
    }
}
