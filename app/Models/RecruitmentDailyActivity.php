<?php

namespace App\Models;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use Database\Factories\RecruitmentDailyActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The authoritative, structured, candidate-linked contact-activity log (Section 8). Performance
 * and incentive calculations must read from here, never from `recruitment_manual_activities`
 * (Section 46).
 */
#[Fillable([
    'recruiter_id',
    'candidate_id',
    'candidate_application_id',
    'activity_type',
    'activity_datetime',
    'outcome',
    'remarks',
    'created_by',
])]
class RecruitmentDailyActivity extends Model
{
    /** @use HasFactory<RecruitmentDailyActivityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'activity_type' => ActivityType::class,
            'outcome' => ActivityOutcome::class,
            'activity_datetime' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recruiter_id');
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
