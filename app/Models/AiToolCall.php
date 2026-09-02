<?php

namespace App\Models;

use App\Enums\AiRiskLevel;
use App\Enums\AiToolCallStatus;
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
])]
class AiToolCall extends Model
{
    /** @use HasFactory<AiToolCallFactory> */
    use HasFactory;

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
