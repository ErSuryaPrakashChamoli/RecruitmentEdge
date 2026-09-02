<?php

namespace App\Models;

use App\Enums\AiRiskLevel;
use Database\Factories\AiActionLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'conversation_id',
    'tool_name',
    'risk_level',
    'entity_type',
    'entity_ids',
    'input',
    'result_summary',
    'status',
])]
class AiActionLog extends Model
{
    /** @use HasFactory<AiActionLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'risk_level' => AiRiskLevel::class,
            'entity_ids' => 'array',
            'input' => 'array',
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
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
