<?php

use App\Enums\ActionPriority;
use App\Enums\EmployeeStatus;
use App\Enums\RecruiterActionStatus;
use App\Enums\RecruiterActionType;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateTimelineEvent;
use App\Models\Employee;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\RecruiterActionService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(RecruiterActionService::class);
    $this->manager = Employee::factory()->create();
    $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->recruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->recruiterUser = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
});

function actionFor(Employee $owner, array $attributes = []): RecruiterAction
{
    return app(RecruiterActionService::class)->create([
        'title' => 'Call the candidate',
        'action_type' => RecruiterActionType::FollowUp,
        'priority' => ActionPriority::High,
        'owner_id' => $owner->id,
        ...$attributes,
    ]);
}

test('creating an action notifies the owner with its priority and audits it', function (): void {
    $action = actionFor($this->recruiter);

    $notification = $this->recruiterUser->notifications()->sole();

    expect($notification->data['viewData']['priority'])->toBe('high')
        ->and($notification->data['viewData']['entity_id'])->toBe($action->id)
        ->and(AuditLog::query()->where('action', 'recruiter_action_created')->exists())->toBeTrue();
});

test('an action for an inactive owner goes to their nearest active manager', function (): void {
    $this->recruiter->update(['status' => EmployeeStatus::Inactive]);

    expect(actionFor($this->recruiter)->owner_id)->toBe($this->manager->id);
});

test('the same dedupe key never creates a second action', function (): void {
    $attributes = ['title' => 'X', 'action_type' => RecruiterActionType::Custom, 'owner_id' => $this->recruiter->id];

    $this->service->createOnce('k-1', $attributes);
    $this->service->createOnce('k-1', $attributes);

    expect(RecruiterAction::query()->count())->toBe(1);
});

test('start, complete and dismiss are audited and closed items cannot be changed again', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    $action = actionFor($this->recruiter, ['candidate_id' => $application->candidate_id, 'candidate_application_id' => $application->id]);

    $this->service->start($action, $this->recruiter);
    $this->service->complete($action, $this->recruiter, 'Spoke to the candidate');

    expect($action->fresh()->status)->toBe(RecruiterActionStatus::Completed)
        ->and(CandidateTimelineEvent::query()->where('title', 'Action completed: Call the candidate')->exists())->toBeTrue()
        ->and(fn () => $this->service->dismiss($action, $this->recruiter, 'x'))->toThrow(DomainException::class, 'already Completed');

    $other = actionFor($this->recruiter);

    expect(fn () => $this->service->dismiss($other, $this->recruiter, ''))->toThrow(DomainException::class, 'reason is required');

    $this->service->dismiss($other, $this->recruiter, 'Duplicate');

    expect(AuditLog::query()->whereIn('action', ['recruiter_action_started', 'recruiter_action_completed', 'recruiter_action_dismissed'])->count())->toBe(3);
});

test('only a manager can reassign, and only within their team', function (): void {
    $action = actionFor($this->recruiter);
    $teammate = Employee::factory()->reportingTo($this->manager)->create();
    User::factory()->create(['employee_id' => $teammate->id]);
    $stranger = Employee::factory()->create();
    User::factory()->create(['employee_id' => $stranger->id]);

    expect(fn () => $this->service->reassign($action, $teammate, $this->recruiterUser, 'swap'))->toThrow(DomainException::class)
        ->and(fn () => $this->service->reassign($action, $stranger, $this->managerUser, 'swap'))->toThrow(DomainException::class, 'your own team');

    $this->service->reassign($action, $teammate, $this->managerUser, 'Workload');

    expect($action->fresh()->owner_id)->toBe($teammate->id)
        ->and(AuditLog::query()->where('action', 'recruiter_action_reassigned')->sole()->changes['reason'])->toBe('Workload');
});

test('visibility follows the hierarchy', function (): void {
    actionFor($this->recruiter);
    actionFor($this->manager);

    expect($this->service->visibleTo($this->recruiterUser)->count())->toBe(1)
        ->and($this->service->visibleTo($this->managerUser)->count())->toBe(2);
});

test('cleanup reassigns actions of deactivated owners and expires stale ones', function (): void {
    $stale = actionFor($this->recruiter, ['due_at' => now()->subDays(20)]);
    $orphan = actionFor($this->recruiter, ['due_at' => now()->addDay()]);
    $this->recruiter->update(['status' => EmployeeStatus::Inactive]);

    $this->artisan('recruitment:automation:cleanup')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(RecruiterActionStatus::Expired)
        ->and($orphan->fresh()->owner_id)->toBe($this->manager->id);
});
