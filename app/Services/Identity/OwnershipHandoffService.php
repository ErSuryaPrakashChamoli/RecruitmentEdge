<?php

namespace App\Services\Identity;

use App\Enums\ActionPriority;
use App\Enums\ApplicationStatus;
use App\Enums\EmployeeStatus;
use App\Enums\InterviewStatus;
use App\Enums\RecruiterActionType;
use App\Enums\RequisitionStatus;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\OwnershipHandoff;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\AutomationRuleService;
use App\Services\RecruiterActionService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: what happens to a person's current work when they lose access. Idempotent per loss
 * of access (dedupe key) and safe to retry — it never restores anything.
 *
 * - Automation rules they own are paused (someone with the authority re-activates and owns them).
 * - Their open Action Center items move to the nearest reachable manager, else an HR
 *   administrator (audited moves).
 * - Open applications, requisitions, scheduled interviews and direct reports are counted into a
 *   handoff record and one Action Center task for the person now responsible. Those records keep
 *   their historical owner; current responsibility changes only when someone reassigns them
 *   through the existing, audited services.
 */
class OwnershipHandoffService
{
    public function __construct(
        private readonly RecruiterActionService $actions,
        private readonly AutomationRuleService $rules,
    ) {}

    public function handOff(int $userId, ?int $employeeId, string $trigger, string $dedupeKey): OwnershipHandoff
    {
        if ($existing = OwnershipHandoff::query()->where('dedupe_key', $dedupeKey)->first()) {
            return $existing;
        }

        $employee = $employeeId !== null ? Employee::withTrashed()->find($employeeId) : null;

        $pausedRules = AutomationRule::query()->active()->where('owner_id', $userId)->get()
            ->each(fn (AutomationRule $rule) => $this->rules->pauseForAuthority($rule, 'its owner lost access'))
            ->count();

        $movedActions = $employee !== null ? $this->actions->reassignFromInactiveOwners($employee->id) : 0;
        $summary = ['automation_rules_paused' => $pausedRules, 'action_items_moved' => $movedActions, ...$this->openWork($employee)];
        $assignee = $employee !== null ? ($this->actions->reachableOwner($employee) ?? $this->actions->fallbackOwner()) : $this->actions->fallbackOwner();
        $needsReassignment = collect($summary)->only(['open_applications', 'open_requisitions', 'scheduled_interviews', 'direct_reports'])->sum() > 0;

        $handoff = OwnershipHandoff::query()->create([
            'dedupe_key' => $dedupeKey,
            'user_id' => $userId,
            'employee_id' => $employee?->id,
            'trigger' => $trigger,
            'assignee_employee_id' => $assignee?->id,
            'status' => $needsReassignment ? OwnershipHandoff::OPEN : OwnershipHandoff::COMPLETED,
            'summary' => $summary,
        ]);

        if ($needsReassignment && $assignee !== null && $employee !== null) {
            $this->actions->createOnce("ownership-handoff:{$handoff->id}", [
                'title' => "Reassign the open work of {$employee->fullName()}",
                'action_type' => RecruiterActionType::Custom,
                'priority' => ActionPriority::High,
                'owner_id' => $assignee->id,
                'reason' => "{$employee->fullName()} no longer has access ({$trigger}). Open: {$summary['open_applications']} application(s), {$summary['open_requisitions']} requisition(s), {$summary['scheduled_interviews']} interview(s), {$summary['direct_reports']} direct report(s).",
                'suggested_action' => 'Reassign each to someone active (Reassign actions on applications, the interview and requisition forms, the org chart for direct reports), then mark the handoff complete in the Access Review.',
            ]);
        }

        AuditLog::record($handoff, 'ownership_handoff_started', null, ['user_id' => $userId, 'employee_id' => $employee?->id, 'trigger' => $trigger, 'assignee_employee_id' => $assignee?->id, ...$summary]);
        Log::info('identity.ownership_handoff', ['handoff_id' => $handoff->id, 'user_id' => $userId, 'trigger' => $trigger, ...$summary]);

        return $handoff;
    }

    /**
     * Current (not historical) work still attributed to $employee.
     *
     * @return array{open_applications: int, open_requisitions: int, scheduled_interviews: int, direct_reports: int}
     */
    public function openWork(?Employee $employee): array
    {
        if ($employee === null) {
            return ['open_applications' => 0, 'open_requisitions' => 0, 'scheduled_interviews' => 0, 'direct_reports' => 0];
        }

        return [
            'open_applications' => CandidateApplication::query()->where('recruiter_id', $employee->id)->whereIn('status', [ApplicationStatus::Active->value, ApplicationStatus::OnHold->value])->count(),
            'open_requisitions' => RecruitmentRequisition::query()
                ->whereNotIn('status', [RequisitionStatus::Closed->value, RequisitionStatus::Cancelled->value])
                ->where(fn (Builder $query) => $query->where('manager_id', $employee->id)->orWhere('reporting_manager_id', $employee->id)->orWhere('hiring_manager_id', $employee->id)->orWhere('assistant_manager_id', $employee->id)->orWhere('vp_hr_id', $employee->id))
                ->count(),
            'scheduled_interviews' => Interview::query()->where('interviewer_id', $employee->id)->whereIn('status', [InterviewStatus::Pending->value, InterviewStatus::Scheduled->value, InterviewStatus::Confirmed->value, InterviewStatus::Rescheduled->value])->count(),
            'direct_reports' => Employee::query()->where('reports_to_id', $employee->id)->where('status', EmployeeStatus::Active->value)->count(),
        ];
    }

    /**
     * Marks a handoff complete (from the Access Review). Refused while work is still open.
     */
    public function complete(OwnershipHandoff $handoff, User $actor): OwnershipHandoff
    {
        if (! $actor->can('users.access.manage')) {
            throw new DomainException('Completing a handoff needs the users.access.manage permission.');
        }

        $open = collect($this->openWork($handoff->employee))->sum();

        if ($open > 0) {
            throw new DomainException("There is still open work ({$open} item(s)) attributed to this person. Reassign it first.");
        }

        $handoff->forceFill(['status' => OwnershipHandoff::COMPLETED, 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
        AuditLog::record($handoff, 'ownership_handoff_completed', ['status' => OwnershipHandoff::OPEN], ['status' => OwnershipHandoff::COMPLETED, 'by_user_id' => $actor->id]);

        return $handoff;
    }
}
