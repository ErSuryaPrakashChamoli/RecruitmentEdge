<?php

namespace App\Models;

use App\Enums\EscalationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One scheduled escalation step of an automation execution. The recipient is resolved through
 * HierarchyService only when the step is due; the step is Stopped instead when the issue was
 * resolved first (see EscalationService).
 */
#[Fillable([
    'automation_execution_id',
    'automation_rule_id',
    'step',
    'target',
    'status',
    'due_at',
    'recipient_employee_id',
    'recipient_user_id',
    'recruiter_action_id',
    'outcome',
    'processed_at',
])]
class AutomationEscalation extends Model
{
    use BelongsToTenant;

    /**
     * Phase 8.6 (D8.6-021): the record of what automation did is never deleted one by one (the
     * scheduled cleanup prunes only skipped runs, in bulk).
     */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('An automation escalation is part of the automation history and cannot be deleted.'));
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => EscalationStatus::class,
            'step' => 'integer',
            'due_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'automation_execution_id');
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function recipientEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recipient_employee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * @return BelongsTo<RecruiterAction, $this>
     */
    public function recruiterAction(): BelongsTo
    {
        return $this->belongsTo(RecruiterAction::class);
    }
}
