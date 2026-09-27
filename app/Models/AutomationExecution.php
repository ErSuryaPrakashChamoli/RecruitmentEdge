<?php

namespace App\Models;

use App\Enums\AutomationExecutionStatus;
use Database\Factories\AutomationExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One automation rule run against one entity — the answer to "why did this happen?": which rule
 * and version, which trigger, which conditions passed, which actions ran and with what result.
 * Created and advanced only by AutomationEngine.
 */
#[Fillable([
    'automation_rule_id',
    'automation_rule_version_id',
    'trigger',
    'subject_type',
    'subject_id',
    'candidate_application_id',
    'recruiter_id',
    'idempotency_key',
    'status',
    'scheduled_for',
    'triggered_at',
    'context',
    'depth',
    'parent_execution_id',
])]
class AutomationExecution extends Model
{
    /** @use HasFactory<AutomationExecutionFactory> */
    use HasFactory, HasUlids;

    /**
     * Phase 8.6 (D8.6-021): the record of what automation did is never deleted one by one (the
     * scheduled cleanup prunes only skipped runs, in bulk).
     */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('An automation execution is part of the automation history and cannot be deleted.'));
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'retry_count' => 0,
        'depth' => 0,
    ];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationExecutionStatus::class,
            'conditions_passed' => 'boolean',
            'condition_results' => 'array',
            'context' => 'array',
            'retry_count' => 'integer',
            'depth' => 'integer',
            'scheduled_for' => 'datetime',
            'triggered_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            AutomationExecutionStatus::Completed,
            AutomationExecutionStatus::PartiallyCompleted,
            AutomationExecutionStatus::Failed,
            AutomationExecutionStatus::Skipped,
            AutomationExecutionStatus::Cancelled,
        ], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, [AutomationExecutionStatus::Failed, AutomationExecutionStatus::PartiallyCompleted], true);
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return BelongsTo<AutomationRuleVersion, $this>
     */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'automation_rule_version_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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
     * @return HasMany<AutomationActionExecution, $this>
     */
    public function actionExecutions(): HasMany
    {
        return $this->hasMany(AutomationActionExecution::class)->orderBy('position');
    }

    /**
     * @return HasMany<AutomationEscalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(AutomationEscalation::class)->orderBy('step');
    }

    /**
     * @return BelongsTo<AutomationExecution, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_execution_id');
    }
}
