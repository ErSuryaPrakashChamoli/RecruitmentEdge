<?php

namespace App\Models;

use Database\Factories\AiToolResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tool_call_id',
    'output',
    'success',
    'error',
])]
class AiToolResult extends Model
{
    /** @use HasFactory<AiToolResultFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'success' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AiToolCall, $this>
     */
    public function toolCall(): BelongsTo
    {
        return $this->belongsTo(AiToolCall::class, 'tool_call_id');
    }
}
