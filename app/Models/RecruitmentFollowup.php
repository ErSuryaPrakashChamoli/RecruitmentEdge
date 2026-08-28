<?php

namespace App\Models;

use App\Enums\FollowupStatus;
use App\Enums\FollowupType;
use Database\Factories\RecruitmentFollowupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'candidate_application_id',
    'recruiter_id',
    'followup_type',
    'followup_date',
    'status',
    'outcome',
    'remarks',
    'created_by',
])]
class RecruitmentFollowup extends Model
{
    /** @use HasFactory<RecruitmentFollowupFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'followup_type' => FollowupType::class,
            'status' => FollowupStatus::class,
            'followup_date' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status === FollowupStatus::Pending && $this->followup_date->isPast();
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
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recruiter_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
