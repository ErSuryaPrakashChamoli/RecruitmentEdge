<?php

namespace App\Models;

use App\Enums\ActionPriority;
use App\Enums\RecruiterActionStatus;
use App\Enums\RecruiterActionType;
use Database\Factories\RecruiterActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A persistent Action Center item owned by one employee (Phase 6). Created by automation rules,
 * escalations or a manager; status changes and reassignment go through RecruiterActionService,
 * which audits them. Not a follow-up: recruitment_followups stays the candidate-contact log.
 */
#[Fillable([
    'title',
    'action_type',
    'priority',
    'status',
    'owner_id',
    'candidate_id',
    'candidate_application_id',
    'requisition_id',
    'subject_type',
    'subject_id',
    'reason',
    'suggested_action',
    'due_at',
    'automation_rule_id',
    'automation_execution_id',
    'dedupe_key',
    'created_by',
])]
class RecruiterAction extends Model
{
    /** @use HasFactory<RecruiterActionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'priority' => 'medium',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => RecruiterActionType::class,
            'priority' => ActionPriority::class,
            'status' => RecruiterActionStatus::class,
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, RecruiterActionStatus::openStatuses(), true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * @param  Builder<RecruiterAction>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', RecruiterActionStatus::openStatuses());
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function owner(): BelongsTo
    {
        // Phase 8.4: a deleted employee's records keep their attribution (and stay visible to the
        // managers above them) — historical ownership is never silently dropped.
        return $this->belongsTo(Employee::class, 'owner_id')->withTrashed();
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
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class);
    }

    /**
     * @return BelongsTo<AutomationExecution, $this>
     */
    public function automationExecution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'completed_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
