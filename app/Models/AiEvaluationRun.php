<?php

namespace App\Models;

use Database\Factories\AiEvaluationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'evaluation_id',
    'passed',
    'actual_output',
    'notes',
    'run_at',
])]
class AiEvaluationRun extends Model
{
    /** @use HasFactory<AiEvaluationRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'actual_output' => 'array',
            'run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AiEvaluation::class, 'evaluation_id');
    }
}
