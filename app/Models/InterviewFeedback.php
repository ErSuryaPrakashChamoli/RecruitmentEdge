<?php

namespace App\Models;

use App\Enums\FeedbackRecommendation;
use Database\Factories\InterviewFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['interview_id', 'interviewer_id', 'score', 'ratings', 'recommendation', 'feedback'])]
class InterviewFeedback extends Model
{
    /** @use HasFactory<InterviewFeedbackFactory> */
    use HasFactory;

    /**
     * Criteria rated 1–5 on every feedback entry, keyed by the JSON key stored in `ratings`.
     *
     * @var array<string, string>
     */
    public const array RATING_CRITERIA = [
        'technical' => 'Technical',
        'communication' => 'Communication',
        'problem_solving' => 'Problem Solving',
        'culture_fit' => 'Culture Fit',
    ];

    public const int RATING_MAX = 5;

    /**
     * The overall `score` column is on a 1–10 scale, so a defaulted score is the criteria average
     * scaled up to 10 (e.g. an average rating of 4 / 5 becomes an 8.0 score).
     */
    public const int SCORE_MAX = 10;

    protected static function booted(): void
    {
        static::saving(function (self $feedback): void {
            $feedback->ratings = self::normalizeRatings($feedback->ratings);

            if ($feedback->score === null && $feedback->ratings !== null) {
                $feedback->score = self::scoreFromRatings($feedback->ratings);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'recommendation' => FeedbackRecommendation::class,
            'score' => 'decimal:1',
            'ratings' => 'array',
        ];
    }

    /**
     * Keeps only known criteria with an integer rating within 1..RATING_MAX; null when none remain.
     *
     * @param  array<string, mixed>|null  $ratings
     * @return array<string, int>|null
     */
    public static function normalizeRatings(?array $ratings): ?array
    {
        $normalized = collect($ratings ?? [])
            ->only(array_keys(self::RATING_CRITERIA))
            ->filter(fn (mixed $rating): bool => is_numeric($rating) && (int) $rating >= 1 && (int) $rating <= self::RATING_MAX)
            ->map(fn (mixed $rating): int => (int) $rating)
            ->all();

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, int>  $ratings
     */
    public static function scoreFromRatings(array $ratings): float
    {
        return round(array_sum($ratings) / count($ratings) / self::RATING_MAX * self::SCORE_MAX, 1);
    }

    /**
     * A compact human-readable summary, e.g. "Technical 4/5 · Communication 3/5".
     */
    public function ratingsSummary(): ?string
    {
        if (blank($this->ratings)) {
            return null;
        }

        return collect(self::RATING_CRITERIA)
            ->filter(fn (string $label, string $key): bool => isset($this->ratings[$key]))
            ->map(fn (string $label, string $key): string => "{$label} {$this->ratings[$key]}/".self::RATING_MAX)
            ->implode(' · ');
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
