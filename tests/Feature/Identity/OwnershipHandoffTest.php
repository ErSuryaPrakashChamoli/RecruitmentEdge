<?php

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\CandidateStage;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interviewer;
use App\Models\OwnershipHandoff;
use App\Models\RecruiterAction;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationAssignmentService;
use App\Services\Automation\AutomationRuleService;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\OwnershipHandoffService;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\StaffAccessService;
use App\Services\InterviewService;
use App\Services\NotificationDispatchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->managerEmployee = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $this->manager = User::factory()->create(['employee_id' => $this->managerEmployee->id])->assignRole('manager');
    $this->recruiterEmployee = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $this->recruiter = User::factory()->create(['employee_id' => $this->recruiterEmployee->id])->assignRole('recruiter');
});

function handoffInterview(Employee $recruiter): void
{
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::Shortlisted]);
    app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(3), 'mode' => 'video_call']);
}

test('automation owned by someone who loses access is paused; its owner of record is kept', function (): void {
    $rule = AutomationRule::factory()->active()->create(['owner_id' => $this->manager->id, 'scope_type' => 'team', 'scope_id' => $this->managerEmployee->id]);

    app(StaffAccessService::class)->revoke($this->manager, $this->vp, 'Left');

    expect($rule->fresh()->status)->toBe(AutomationRuleStatus::Paused)
        ->and($rule->fresh()->owner_id)->toBe($this->manager->id)
        ->and(AuditLog::query()->where('action', 'automation_rule_paused_authority')->where('auditable_id', $rule->id)->exists())->toBeTrue();
});

test('a rule whose owner lost the authority is paused when it would run, and does nothing', function (): void {
    $rule = AutomationRule::factory()->active()->create(['owner_id' => $this->manager->id, 'scope_type' => 'team', 'scope_id' => $this->managerEmployee->id]);
    app(RoleAssignmentService::class)->syncUserRoles($this->manager, [Role::byKeyOrFail('recruiter')->id], $this->chro);

    handoffInterview($this->recruiterEmployee);

    expect(AutomationExecution::query()->sole()->status)->toBe(AutomationExecutionStatus::Cancelled)
        ->and(AutomationExecution::query()->sole()->skip_reason)->toContain('owner can no longer run automation')
        ->and($rule->fresh()->status)->toBe(AutomationRuleStatus::Paused)
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('re-activating a paused rule makes the activator its accountable owner', function (): void {
    $rule = AutomationRule::factory()->active()->create(['owner_id' => $this->manager->id, 'scope_type' => 'team', 'scope_id' => $this->managerEmployee->id]);
    app(StaffAccessService::class)->revoke($this->manager, $this->vp, 'Left');

    app(AutomationRuleService::class)->activate($rule->fresh(), $this->vp, 'Reviewed and approved');

    expect($rule->fresh()->status)->toBe(AutomationRuleStatus::Active)
        ->and($rule->fresh()->owner_id)->toBe($this->vp->id)
        ->and(AuditLog::query()->where('action', 'automation_rule_owner_transferred')->exists())->toBeTrue();
});

test('a departed recruiter\'s open work is counted into a handoff for their manager, historical owners untouched', function (): void {
    $applications = CandidateApplication::factory()->count(2)->create(['recruiter_id' => $this->recruiterEmployee->id]);
    RecruiterAction::factory()->create(['owner_id' => $this->recruiterEmployee->id, 'status' => 'open']);

    app(EmployeeLifecycleService::class)->recordSeparation($this->recruiterEmployee, $this->vp, ['separation_date' => now()->subDay()->toDateString(), 'separation_reason' => 'resignation']);

    $handoff = OwnershipHandoff::query()->sole();

    expect($handoff->status)->toBe(OwnershipHandoff::OPEN)
        ->and($handoff->assignee_employee_id)->toBe($this->managerEmployee->id)
        ->and($handoff->summary)->toMatchArray(['open_applications' => 2, 'action_items_moved' => 1])
        ->and($applications->first()->fresh()->recruiter_id)->toBe($this->recruiterEmployee->id)
        ->and(RecruiterAction::query()->where('owner_id', $this->managerEmployee->id)->count())->toBe(2)
        ->and(RecruiterAction::query()->where('dedupe_key', "ownership-handoff:{$handoff->id}")->exists())->toBeTrue();
});

test('a retried handoff changes nothing', function (): void {
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterEmployee->id]);
    $service = app(OwnershipHandoffService::class);

    $first = $service->handOff($this->recruiter->id, $this->recruiterEmployee->id, 'revoked', 'key-1');
    $second = $service->handOff($this->recruiter->id, $this->recruiterEmployee->id, 'revoked', 'key-1');

    expect($second->id)->toBe($first->id)
        ->and(OwnershipHandoff::query()->count())->toBe(1)
        ->and(RecruiterAction::query()->where('dedupe_key', "ownership-handoff:{$first->id}")->count())->toBe(1);
});

test('a handoff is completed only once the work has been explicitly reassigned', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterEmployee->id]);
    app(StaffAccessService::class)->revoke($this->recruiter, $this->vp, 'Left');
    $handoff = OwnershipHandoff::query()->sole();

    expect(fn () => app(OwnershipHandoffService::class)->complete($handoff, $this->vp))->toThrow(DomainException::class, 'still open work');

    $replacement = Employee::factory()->reportingTo($this->managerEmployee)->create();
    User::factory()->create(['employee_id' => $replacement->id])->assignRole('recruiter');
    app(ApplicationAssignmentService::class)->reassignRecruiter($application, $replacement, $this->vp, 'Handoff');
    app(OwnershipHandoffService::class)->complete($handoff->fresh(), $this->vp);

    expect($handoff->fresh()->status)->toBe(OwnershipHandoff::COMPLETED)
        ->and(AuditLog::query()->where('action', 'application_reassigned')->exists())->toBeTrue();
});

test('Action Center items of an owner who can no longer be reached move to the nearest reachable manager', function (): void {
    $item = RecruiterAction::factory()->create(['owner_id' => $this->recruiterEmployee->id, 'status' => 'open']);
    app(StaffAccessService::class)->suspend($this->recruiter, $this->vp, 'Investigation');

    expect($item->fresh()->owner_id)->toBe($this->managerEmployee->id);
});

test('departed staff receive no operational alerts; the nearest active manager does', function (): void {
    app(StaffAccessService::class)->revoke($this->recruiter, $this->vp, 'Left');

    app(NotificationDispatchService::class)->alert($this->recruiter, 'Recruitment', 'SLA breach', 'An application is waiting.');

    expect($this->recruiter->notifications()->count())->toBe(0)
        ->and($this->manager->notifications()->count())->toBe(1);
});

test('an alert with nobody reachable to take it is dropped, never sent to the departed person', function (): void {
    config(['identity.handoff_fallback_permission' => 'nobody.has.this']);
    $lone = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');
    app(StaffAccessService::class)->revoke($lone, $this->chro, 'Left');

    app(NotificationDispatchService::class)->alert($lone, 'Recruitment', 'SLA breach', 'An application is waiting.');

    expect(DatabaseNotification::query()->count())->toBe(0);
});

test('a current recipient is unchanged', function (): void {
    app(NotificationDispatchService::class)->alert($this->recruiter, 'Recruitment', 'SLA breach', 'An application is waiting.');

    expect($this->recruiter->notifications()->count())->toBe(1)
        ->and($this->manager->notifications()->count())->toBe(0);
});

test('the hourly recruitment alerts skip an inactive recruiter and reach their manager', function (): void {
    handoffInterview($this->recruiterEmployee);
    app(EmployeeLifecycleService::class)->deactivate($this->recruiterEmployee, $this->chro, 'Leave');
    $before = $this->recruiter->notifications()->count();
    $managerBefore = $this->manager->notifications()->count();
    $this->travelTo(now()->addDays(2));

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect($this->recruiter->notifications()->count())->toBe($before)
        ->and($this->manager->notifications()->count())->toBeGreaterThan($managerBefore);
});
