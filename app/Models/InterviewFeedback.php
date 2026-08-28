<?php

namespace App\Models;

use App\Enums\FeedbackRecommendation;
use Database\Factories\InterviewFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['interview_id', 'interviewer_id', 'score', 'recommendation', 'feedback'])]
class InterviewFeedback extends Model
{
    /** @use HasFactory<InterviewFeedbackFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'recommendation' => FeedbackRecommendation::class,
            'score' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<Interview, $this>
     */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }
}
