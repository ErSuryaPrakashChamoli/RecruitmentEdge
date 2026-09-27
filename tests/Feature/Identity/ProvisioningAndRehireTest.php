<?php

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Enums\JoiningStatus;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Events\UserProvisioned;
use App\Mail\StaffAccessInvitation;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidatePortalAccount;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\RecruitmentRequisition;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeConversionService;
use App\Services\HierarchyService;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\RoleAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Mail::fake();
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->managerEmployee = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $this->recruiter = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $this->candidate = Candidate::factory()->create(['full_name' => 'Asha Verma', 'email' => 'asha.verma@example.com']);
    $this->conversion = app(EmployeeConversionService::class);
});

function provisioningJoining(Candidate $candidate, Employee $recruiter, ?Employee $manager = null): CandidateJoining
{
    $requisition = RecruitmentRequisition::factory()->create(['reporting_manager_id' => $manager?->id]);
    $application = CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'requisition_id' => $requisition->id, 'recruiter_id' => $recruiter->id]);

    return CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->subDays(30)]);
}

test('conversion provisions employee, login, base role, manager and access in one step', function (): void {
    Event::fake([UserProvisioned::class]);

    $employee = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);
    $user = User::query()->where('employee_id', $employee->id)->sole();

    expect($employee->reports_to_id)->toBe($this->managerEmployee->id)
        ->and($employee->status)->toBe(EmployeeStatus::Active)
        ->and($user->email)->toBe('asha.verma@example.com')
        ->and($user->fresh()->access_status)->toBe(AccessState::Active)
        ->and($user->getRoleNames()->all())->toBe(['employee'])
        ->and(AuditLog::query()->where('action', 'user_provisioned')->where('auditable_id', $user->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'employee_converted')->sole()->getAttribute('changes'))->toMatchArray(['user_id' => $user->id, 'rehire' => false]);

    Event::assertDispatched(UserProvisioned::class, fn (UserProvisioned $event) => $event->userId === $user->id && $event->source === 'conversion');
    Mail::assertSent(StaffAccessInvitation::class, fn (StaffAccessInvitation $mail) => $mail->hasTo('asha.verma@example.com'));
});

test('the new employee is placed in the hierarchy under their manager', function (): void {
    $employee = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);

    expect(app(HierarchyService::class)->descendantIdsOf($this->managerEmployee->id)->contains($employee->id))->toBeTrue();
});

test('conversion needs employees.convert; users.manage alone cannot convert, and no privileged role is granted', function (): void {
    $userAdmin = User::factory()->create()->assignRole(Role::create(['name' => 'user_admin'])->givePermissionTo(['users.manage', 'hierarchy.view-all', 'joining.confirm']));
    $joining = provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee);

    expect(fn () => $this->conversion->convert($joining, $userAdmin))->toThrow(DomainException::class, 'employees.convert')
        ->and(User::query()->where('email', 'asha.verma@example.com')->exists())->toBeFalse();
});

test('a manager must be chosen, inside the converter\'s hierarchy', function (): void {
    $joining = provisioningJoining($this->candidate, $this->recruiter);
    $outside = Employee::factory()->reportingTo($this->chroEmployee)->create();

    expect(fn () => $this->conversion->convert($joining, $this->vp))->toThrow(DomainException::class, 'Choose the manager')
        ->and(fn () => $this->conversion->convert($joining, $this->vp, $outside->id))->toThrow(DomainException::class, 'outside your hierarchy')
        ->and(Employee::query()->where('candidate_id', $this->candidate->id)->exists())->toBeFalse();
});

test('an email already used by another login stops the conversion before anything is written', function (): void {
    User::factory()->create(['email' => 'asha.verma@example.com']);

    expect(fn () => $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp))->toThrow(DomainException::class, 'already uses this email')
        ->and(Employee::query()->where('candidate_id', $this->candidate->id)->exists())->toBeFalse();
});

test('the candidate portal login is retired on conversion; the candidate record stays', function (): void {
    $account = CandidatePortalAccount::factory()->create(['candidate_id' => $this->candidate->id, 'is_active' => true]);

    $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);

    expect($account->fresh()->is_active)->toBeFalse()
        ->and($this->candidate->fresh())->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'portal_deactivated')->exists())->toBeTrue();
});

test('duplicate conversion is refused', function (): void {
    $joining = provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee);
    $this->conversion->convert($joining, $this->vp);

    expect(fn () => $this->conversion->convert($joining->fresh(), $this->vp))->toThrow(DomainException::class, 'already been converted')
        ->and(User::query()->where('email', 'asha.verma@example.com')->count())->toBe(1);
});

test('a separated employee is rehired: the same record and login come back, history kept, no old authority', function (): void {
    $employee = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);
    $user = User::query()->where('employee_id', $employee->id)->sole();
    app(RoleAssignmentService::class)->syncUserRoles($user, [Role::byKeyOrFail('recruiter')->id], $this->chro);
    $separation = app(EmployeeLifecycleService::class)->recordSeparation($employee, $this->vp, ['separation_date' => now()->subDays(3)->toDateString(), 'separation_reason' => 'resignation']);

    expect($user->fresh()->access_status)->toBe(AccessState::Revoked);

    $newManager = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $rehired = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $newManager), $this->vp);

    expect($rehired->id)->toBe($employee->id)
        ->and($rehired->fresh()->status)->toBe(EmployeeStatus::Active)
        ->and($rehired->fresh()->reports_to_id)->toBe($newManager->id)
        ->and(Employee::query()->where('candidate_id', $this->candidate->id)->count())->toBe(1)
        ->and($user->fresh()->access_status)->toBe(AccessState::Active)
        ->and($user->fresh()->getRoleNames()->all())->toBe(['employee'])
        ->and($separation->fresh()->cancelled_at)->toBeNull()
        ->and($separation->fresh()->effective_applied_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'employee_rehired')->exists())->toBeTrue();
});

test('a candidate whose earlier employee record was deleted is rehired, never hitting a database error', function (): void {
    $employee = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);
    app(EmployeeLifecycleService::class)->recordSeparation($employee, $this->vp, ['separation_date' => now()->subDays(3)->toDateString(), 'separation_reason' => 'resignation']);
    $employee->fresh()->delete();

    $rehired = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);

    expect($rehired->id)->toBe($employee->id)
        ->and($rehired->fresh()->trashed())->toBeFalse();
});

test('cancelling a separation needs the permission and a reason, and before it takes effect changes nothing else', function (): void {
    $employee = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);
    $separation = app(EmployeeLifecycleService::class)->recordSeparation($employee, $this->vp, ['separation_date' => now()->addWeek()->toDateString(), 'separation_reason' => 'resignation']);
    $manager = User::factory()->create(['employee_id' => $this->managerEmployee->id])->assignRole('manager');

    expect(fn () => app(EmployeeLifecycleService::class)->cancelSeparation($separation, $manager, 'Stayed'))->toThrow(DomainException::class, 'employees.separation.cancel')
        ->and(fn () => app(EmployeeLifecycleService::class)->cancelSeparation($separation, $this->vp, ' '))->toThrow(DomainException::class, 'reason');

    app(EmployeeLifecycleService::class)->cancelSeparation($separation, $this->vp, 'Resignation withdrawn');

    expect($separation->fresh()->cancelled_at)->not->toBeNull()
        ->and($separation->fresh()->cancellation_reason)->toBe('Resignation withdrawn')
        ->and(AuditLog::query()->where('action', 'separation_cancelled')->sole()->getAttribute('changes'))->toMatchArray(['was_effective' => false])
        ->and($employee->fresh()->status)->toBe(EmployeeStatus::Active);

    $this->travel(10)->days();
    $this->artisan('identity:enforce-separations')->assertSuccessful();

    expect(User::query()->where('employee_id', $employee->id)->sole()->access_status)->toBe(AccessState::Active);
});

test('cancelling an effective separation restores employment but never silently restores access or old roles', function (): void {
    $employee = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);
    $user = User::query()->where('employee_id', $employee->id)->sole();
    app(RoleAssignmentService::class)->syncUserRoles($user, [Role::byKeyOrFail('manager')->id], $this->chro);
    $separation = app(EmployeeLifecycleService::class)->recordSeparation($employee, $this->vp, ['separation_date' => now()->subDays(2)->toDateString(), 'separation_reason' => 'resignation']);

    app(EmployeeLifecycleService::class)->cancelSeparation($separation, $this->vp, 'Recorded in error');

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active)
        ->and($user->fresh()->access_status)->toBe(AccessState::Revoked)
        ->and($user->fresh()->roles)->toBeEmpty()
        ->and(AuditLog::query()->where('action', 'separation_cancelled')->sole()->getAttribute('changes'))->toMatchArray(['was_effective' => true, 'access_review_required' => true]);
});

test('outcomes recorded from a cancelled separation are voided, keeping the original version, then observed again', function (): void {
    $employee = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $snapshot = HiringOutcomeSnapshot::factory()->create(['employee_id' => $employee->id, 'joined_on' => now()->subDays(40)->toDateString(), 'captured_at' => now()->subDays(40)]);
    $separation = app(EmployeeLifecycleService::class)->recordSeparation($employee, $this->vp, ['separation_date' => now()->subDays(20)->toDateString(), 'separation_reason' => 'resignation']);
    $recorded = HiringOutcome::query()->where('source_type', (new EmployeeSeparation)->getMorphClass())->where('source_id', $separation->id)->current()->sole();

    app(EmployeeLifecycleService::class)->cancelSeparation($separation, $this->vp, 'Recorded in error');

    // Phase 8.5 (DF-11): the void is kept in history and the checkpoint is observed again as a new
    // version — it no longer sits in "awaiting evaluation" forever.
    $void = HiringOutcome::query()->where('dedupe_key', $recorded->dedupe_key)->where('supersedes_id', $recorded->id)->sole();
    $current = HiringOutcome::query()->where('dedupe_key', $recorded->dedupe_key)->current()->sole();

    expect($void->state)->toBe(OutcomeState::Void)
        ->and($void->is_current)->toBeFalse()
        ->and($current->supersedes_id)->toBe($void->id)
        ->and($current->result)->toBe(OutcomeResult::Active)
        ->and($recorded->fresh()->is_current)->toBeFalse()
        ->and($snapshot->fresh())->not->toBeNull();
});

test('a separation followed by a rehire cannot be cancelled', function (): void {
    $employee = $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);
    $separation = app(EmployeeLifecycleService::class)->recordSeparation($employee, $this->vp, ['separation_date' => now()->subDays(3)->toDateString(), 'separation_reason' => 'resignation']);
    $this->conversion->convert(provisioningJoining($this->candidate, $this->recruiter, $this->managerEmployee), $this->vp);

    expect(fn () => app(EmployeeLifecycleService::class)->cancelSeparation($separation->fresh(), $this->vp, 'x'))->toThrow(DomainException::class, 'rehired');
});
