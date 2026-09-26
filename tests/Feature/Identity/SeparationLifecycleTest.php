<?php

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeType;
use App\Events\EmployeeSeparated;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\LastChroProtectedException;
use App\Services\Identity\StaffAccessService;
use App\Services\Outcomes\OutcomeCalculator;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->employee = Employee::factory()->reportingTo($this->vpEmployee)->create(['date_of_joining' => now()->subYear()]);
    $this->user = User::factory()->create(['employee_id' => $this->employee->id])->assignRole('recruiter');
    $this->lifecycle = app(EmployeeLifecycleService::class);
});

function separationFor(Employee $employee, User $actor, string $lastDay, array $extra = []): EmployeeSeparation
{
    return app(EmployeeLifecycleService::class)->recordSeparation($employee, $actor, ['separation_date' => $lastDay, 'separation_reason' => 'resignation', ...$extra]);
}

test('a future separation does not end access before its effective date', function (): void {
    $separation = separationFor($this->employee, $this->vp, now()->addDays(8)->toDateString());

    expect($separation->effective_applied_at)->toBeNull()
        ->and($this->employee->fresh()->status)->toBe(EmployeeStatus::Active)
        ->and($this->user->fresh()->access_status)->toBe(AccessState::Active)
        ->and($this->user->fresh()->can('pipeline.transition'))->toBeTrue();

    $this->travelTo(now()->addDays(8)->endOfDay());
    expect($this->user->fresh()->can('pipeline.transition'))->toBeTrue('access continues through the last working day');
});

test('the day after the last working day the gate refuses the login even before enforcement runs', function (): void {
    separationFor($this->employee, $this->vp, now()->addDays(2)->toDateString());
    $this->travelTo(now()->addDays(3)->startOfDay()->addMinute());

    expect($this->user->fresh()->access_status)->toBe(AccessState::Active)
        ->and($this->user->fresh()->can('pipeline.transition'))->toBeFalse()
        ->and($this->user->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

test('enforcement separates the employee, revokes the login and announces it once', function (): void {
    Event::fake([EmployeeSeparated::class]);
    $separation = separationFor($this->employee, $this->vp, now()->addDays(2)->toDateString());
    $this->travelTo(now()->addDays(3));

    $this->artisan('identity:enforce-separations')->assertSuccessful();
    $this->artisan('identity:enforce-separations')->assertSuccessful();

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($this->user->fresh()->access_status)->toBe(AccessState::Revoked)
        ->and($this->user->fresh()->roles)->toBeEmpty()
        ->and($separation->fresh()->effective_applied_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'separation_effective')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'access_revoked')->where('auditable_id', $this->user->id)->count())->toBe(1);

    Event::assertDispatchedTimes(EmployeeSeparated::class, 1);
    Event::assertDispatched(EmployeeSeparated::class, fn (EmployeeSeparated $event) => $event->employeeId === $this->employee->id && $event->userId === $this->user->id);
});

test('applying the same separation again (a retried job) changes nothing', function (): void {
    $separation = separationFor($this->employee, $this->vp, now()->subDays(2)->toDateString());

    expect($this->lifecycle->applyEffectiveSeparation($separation))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'separation_effective')->count())->toBe(1);
});

test('a dry run reports due separations without applying them', function (): void {
    separationFor($this->employee, $this->vp, now()->addDay()->toDateString());
    $this->travelTo(now()->addDays(2));

    $this->artisan('identity:enforce-separations --dry-run')->expectsOutputToContain('due 1')->assertSuccessful();

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Active);
});

test('a separation recorded after the last working day takes effect at once', function (): void {
    separationFor($this->employee, $this->vp, now()->subDays(3)->toDateString());

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($this->user->fresh()->access_status)->toBe(AccessState::Revoked);
});

test('access can end ahead of a future last working day while employment runs to the date', function (): void {
    separationFor($this->employee, $this->vp, now()->addWeek()->toDateString(), ['revoke_access_now' => true]);

    expect($this->user->fresh()->access_status)->toBe(AccessState::Revoked)
        ->and($this->employee->fresh()->status)->toBe(EmployeeStatus::Active);
});

test('a panel request after the separation took effect signs the person out and applies it', function (): void {
    separationFor($this->employee, $this->vp, now()->addDay()->toDateString());
    $this->actingAs($this->user)->get('/admin')->assertOk();
    $this->travelTo(now()->addDays(2));

    $this->get('/admin')->assertRedirect(Filament::getLoginUrl());

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($this->user->fresh()->access_status)->toBe(AccessState::Revoked);
});

test('recording a separation needs the permission, the hierarchy, and never covers yourself', function (): void {
    $manager = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->vpEmployee)->create()->id])->assignRole('manager');
    $outsider = Employee::factory()->create();

    expect(fn () => separationFor($this->employee, $manager, now()->addDay()->toDateString()))->toThrow(DomainException::class, 'employees.separation.manage')
        ->and(fn () => separationFor($outsider, $this->vp, now()->addDay()->toDateString()))->toThrow(DomainException::class, 'employees.separation.manage')
        ->and(fn () => separationFor($this->vpEmployee, $this->vp, now()->addDay()->toDateString()))->toThrow(DomainException::class, 'your own separation')
        ->and(EmployeeSeparation::query()->count())->toBe(0);
});

test('an employee cannot have two open separations', function (): void {
    separationFor($this->employee, $this->vp, now()->addDays(5)->toDateString());

    expect(fn () => separationFor($this->employee, $this->vp, now()->addDays(9)->toDateString()))->toThrow(DomainException::class, 'already has a separation');
});

test('the last effective CHRO cannot be separated, and the attempt is audited', function (): void {
    $hrAdmin = User::factory()->create()->assignRole(Role::create(['name' => 'hr_admin'])->givePermissionTo(['employees.separation.manage', 'hierarchy.view-all']));

    expect(fn () => separationFor($this->chroEmployee, $hrAdmin, now()->subDay()->toDateString()))->toThrow(LastChroProtectedException::class)
        ->and(EmployeeSeparation::query()->count())->toBe(0)
        ->and($this->chro->fresh()->access_status)->toBe(AccessState::Active)
        ->and(AuditLog::query()->where('action', 'last_chro_protected')->where('auditable_id', $this->chro->id)->exists())->toBeTrue();
});

test('a future date can be moved before it takes effect but not after', function (): void {
    $separation = separationFor($this->employee, $this->vp, now()->addDays(5)->toDateString());
    $this->lifecycle->correctSeparation($separation, $this->vp, ['separation_date' => now()->addDays(10)->toDateString()]);

    expect($separation->fresh()->separation_date->toDateString())->toBe(now()->addDays(10)->toDateString());

    $this->lifecycle->correctSeparation($separation, $this->vp, ['separation_date' => now()->subDay()->toDateString()]);

    expect($this->user->fresh()->access_status)->toBe(AccessState::Revoked)
        ->and(fn () => $this->lifecycle->correctSeparation($separation->fresh(), $this->vp, ['separation_date' => now()->addDays(30)->toDateString()]))->toThrow(DomainException::class, 'already taken effect');
});

test('the separation lifecycle cannot be written outside the service', function (): void {
    $separation = separationFor($this->employee, $this->vp, now()->addDays(5)->toDateString());

    expect(fn () => $separation->update(['separation_date' => now()->subDay()->toDateString()]))->toThrow(LogicException::class, 'EmployeeLifecycleService')
        ->and(fn () => $this->employee->update(['status' => EmployeeStatus::Separated]))->toThrow(LogicException::class, 'EmployeeLifecycleService');
});

test('deactivating employment suspends the login; reactivating restores it', function (): void {
    $this->lifecycle->deactivate($this->employee, $this->chro, 'Long leave');

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Inactive)
        ->and($this->user->fresh()->access_status)->toBe(AccessState::Suspended)
        ->and($this->user->fresh()->hasRole('recruiter'))->toBeTrue();

    $this->lifecycle->reactivate($this->employee, $this->chro, 'Back');

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Active)
        ->and($this->user->fresh()->access_status)->toBe(AccessState::Active);
});

test('reactivating employment never lifts a suspension an administrator imposed separately', function (): void {
    app(StaffAccessService::class)->suspend($this->user, $this->vp, 'Investigation');
    lifecycleFixture(fn () => $this->employee->update(['status' => EmployeeStatus::Inactive]));

    $this->lifecycle->reactivate($this->employee, $this->chro, 'Back');

    expect($this->user->fresh()->access_status)->toBe(AccessState::Suspended);
});

test('status observations stay as before: a checkpoint before the separation date observes the separated employee as active', function (): void {
    $snapshot = HiringOutcomeSnapshot::factory()->create(['employee_id' => $this->employee->id, 'joined_on' => now()->subDays(40)->toDateString(), 'captured_at' => now()->subDays(40)]);
    separationFor($this->employee, $this->vp, now()->subDays(2)->toDateString());

    $observed = app(OutcomeCalculator::class)->statusObservation($snapshot->fresh(), OutcomeType::StatusObserved30d);

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($observed->result)->toBe(OutcomeResult::Active);
});

test('a separation from before a rehire never counts against the later employment', function (): void {
    lifecycleFixture(fn () => EmployeeSeparation::factory()->create(['employee_id' => $this->employee->id, 'separation_date' => now()->subDays(200)->toDateString(), 'effective_applied_at' => now()->subDays(199)]));
    $snapshot = HiringOutcomeSnapshot::factory()->create(['employee_id' => $this->employee->id, 'joined_on' => now()->subDays(40)->toDateString(), 'captured_at' => now()->subDays(40)]);

    $observed = app(OutcomeCalculator::class)->statusObservation($snapshot, OutcomeType::StatusObserved30d);

    expect($observed->result)->toBe(OutcomeResult::Active)
        ->and(HiringOutcome::query()->where('result', OutcomeResult::SeparatedBeforeCheckpoint)->exists())->toBeFalse();
});

test('a cancelled separation is never evidence of an exit', function (): void {
    lifecycleFixture(fn () => EmployeeSeparation::factory()->create(['employee_id' => $this->employee->id, 'separation_date' => now()->subDays(20)->toDateString(), 'cancelled_at' => now()->subDays(19)]));
    $snapshot = HiringOutcomeSnapshot::factory()->create(['employee_id' => $this->employee->id, 'joined_on' => now()->subDays(40)->toDateString(), 'captured_at' => now()->subDays(40)]);

    expect(app(OutcomeCalculator::class)->statusObservation($snapshot, OutcomeType::StatusObserved30d)->result)->toBe(OutcomeResult::Active);
});
