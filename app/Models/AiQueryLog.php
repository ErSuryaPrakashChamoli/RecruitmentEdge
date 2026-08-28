<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Logs every question asked of AiAssistantService — auditable and useful for spotting knowledge
 * base gaps (questions with no matched articles).
 */
#[Fillable(['user_id', 'question', 'matched_article_ids', 'answer'])]
class AiQueryLog extends Model
{
    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'matched_article_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
