<?php

namespace App\Models;

use App\Enums\FollowupStatus;
use App\Enums\FollowupType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
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
    use Auditable, BelongsToTenant, HasFactory;

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
        // Phase 8.4: a deleted employee's records keep their attribution (and stay visible to the
        // managers above them) — historical ownership is never silently dropped.
        return $this->belongsTo(Employee::class, 'recruiter_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * Phase 8.6 (D8.6-025): free-text remarks are recorded as changed, never copied into the audit trail.
     *
     * @return array<int, string>
     */
    public function auditRedactedAttributes(): array
    {
        return ['remarks'];
    }
}
