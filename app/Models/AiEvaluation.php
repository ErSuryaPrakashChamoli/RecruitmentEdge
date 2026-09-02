<?php

namespace App\Models;

use Database\Factories\AiEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'category',
    'question',
    'expected_intent',
    'expected_tool',
    'expected_permission',
    'context',
    'assertions',
])]
class AiEvaluation extends Model
{
    /** @use HasFactory<AiEvaluationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'assertions' => 'array',
        ];
    }

    /**
     * @return HasMany<AiEvaluationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AiEvaluationRun::class, 'evaluation_id')->latest('run_at');
    }
}
