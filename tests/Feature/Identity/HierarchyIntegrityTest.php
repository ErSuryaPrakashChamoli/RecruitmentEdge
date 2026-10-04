<?php

use App\Enums\EmployeeStatus;
use App\Filament\Pages\OrganizationHierarchy;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\HierarchyIntegrityService;
use App\Services\Identity\StaffAccessService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->managerEmployee = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $this->manager = User::factory()->create(['employee_id' => $this->managerEmployee->id])->assignRole('manager');
    $this->recruiterEmployee = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $this->outsiderEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->hierarchyService = app(HierarchyIntegrityService::class);
});

test('a hierarchy.reassign holder cannot pull someone from outside their hierarchy under themselves', function (): void {
    expect(fn () => $this->hierarchyService->reassign($this->outsiderEmployee, $this->vpEmployee->id, $this->vp))->toThrow(DomainException::class, 'outside your hierarchy')
        ->and($this->outsiderEmployee->fresh()->reports_to_id)->toBe($this->chroEmployee->id)
        ->and(app(HierarchyService::class)->canView($this->vp, $this->outsiderEmployee))->toBeFalse();
});

test('nobody can move someone in their team under a manager outside it, or move themselves', function (): void {
    expect(fn () => $this->hierarchyService->reassign($this->recruiterEmployee, $this->outsiderEmployee->id, $this->vp))->toThrow(DomainException::class, 'outside your hierarchy')
        ->and(fn () => $this->hierarchyService->reassign($this->vpEmployee, $this->outsiderEmployee->id, $this->vp))->toThrow(DomainException::class, 'your own reporting line');
});

test('self-parenting and indirect cycles are refused before anything is written', function (): void {
    expect(fn () => $this->hierarchyService->reassign($this->managerEmployee, $this->managerEmployee->id, $this->chro))->toThrow(DomainException::class)
        ->and(fn () => $this->hierarchyService->reassign($this->vpEmployee, $this->recruiterEmployee->id, $this->chro))->toThrow(DomainException::class, 'own reporting line')
        ->and($this->vpEmployee->fresh()->reports_to_id)->toBe($this->chroEmployee->id);
});

test('an authorised move updates the tree and is audited', function (): void {
    $this->hierarchyService->reassign($this->recruiterEmployee, $this->vpEmployee->id, $this->vp);

    expect($this->recruiterEmployee->fresh()->reports_to_id)->toBe($this->vpEmployee->id)
        ->and(app(HierarchyService::class)->descendantIdsOf($this->managerEmployee->id)->all())->toBe([$this->managerEmployee->id])
        ->and(AuditLog::query()->where('action', 'reporting_line_changed')->sole()->old_values)->toBe(['reports_to_id' => $this->managerEmployee->id]);
});

test('the new manager must be a current employee', function (): void {
    lifecycleFixture(fn () => $this->outsiderEmployee->update(['status' => EmployeeStatus::Inactive]));

    expect(fn () => $this->hierarchyService->reassign($this->recruiterEmployee, $this->outsiderEmployee->id, $this->chro))->toThrow(DomainException::class, 'current (active) employee');
});

test('a holder of a protected role is moved only by a holder of it', function (): void {
    $orgAdmin = User::factory()->create()->assignRole(Role::create(['name' => 'org_admin'])->givePermissionTo(['hierarchy.reassign', 'hierarchy.view-all']));

    expect(fn () => $this->hierarchyService->reassign($this->chroEmployee, $this->outsiderEmployee->id, $orgAdmin))->toThrow(DomainException::class, 'Only a holder');
});

test('the reporting line cannot be written outside the hierarchy service', function (): void {
    expect(fn () => $this->recruiterEmployee->update(['reports_to_id' => $this->vpEmployee->id]))->toThrow(LogicException::class);
});

test('the org chart\'s reassign action refuses a tampered target outside the actor\'s hierarchy', function (): void {
    actingAs($this->vp);

    Livewire::test(OrganizationHierarchy::class)
        ->callAction('reassignManager', data: ['reports_to_id' => $this->vpEmployee->id], arguments: ['employeeId' => $this->outsiderEmployee->id]);

    expect($this->outsiderEmployee->fresh()->reports_to_id)->toBe($this->chroEmployee->id);
});

test('an employee with direct reports or an active login cannot be deleted; permanent deletion is never allowed', function (): void {
    expect(fn () => $this->hierarchyService->delete($this->managerEmployee, $this->chro))->toThrow(DomainException::class, 'direct reports');

    $leaf = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $leafLogin = User::factory()->create(['employee_id' => $leaf->id])->assignRole('recruiter');

    expect(fn () => $this->hierarchyService->delete($leaf, $this->chro))->toThrow(DomainException::class, 'active login')
        ->and($this->chro->can('forceDelete', $leaf))->toBeFalse();

    app(StaffAccessService::class)->revoke($leafLogin, $this->chro, 'Left');
    $this->hierarchyService->delete($leaf, $this->chro);

    expect($leaf->fresh()->trashed())->toBeTrue();
});

test('restoring is limited to the actor\'s hierarchy and never restores access', function (): void {
    $leaf = Employee::factory()->reportingTo($this->outsiderEmployee)->create();
    $login = User::factory()->create(['employee_id' => $leaf->id])->assignRole('recruiter');
    app(StaffAccessService::class)->revoke($login, $this->chro, 'Left');
    $leaf->delete();
    $hrAdmin = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->vpEmployee)->create()->id])->assignRole(Role::create(['name' => 'hr_admin'])->givePermissionTo('users.manage'));

    expect($hrAdmin->can('restore', $leaf->fresh()))->toBeFalse();

    $this->hierarchyService->restore($leaf->fresh(), $this->chro);

    expect($leaf->fresh()->trashed())->toBeFalse()
        ->and($login->fresh()->access_status->value)->toBe('revoked');
});

test('a deleted recruiter\'s applications keep their attribution and their pages keep working', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterEmployee->id]);
    $this->recruiterEmployee->delete();

    expect($application->fresh()->recruiter?->id)->toBe($this->recruiterEmployee->id)
        ->and($this->manager->can('view', $application->fresh()))->toBeTrue()
        ->and($this->chro->can('view', $application->fresh()))->toBeTrue();

    actingAs($this->manager);
    get("/admin/acme/candidate-applications/{$application->id}")->assertOk();
});

test('a manager whose employment is made inactive keeps no authority over their team', function (): void {
    expect($this->manager->can('candidates.viewAny'))->toBeTrue();

    app(EmployeeLifecycleService::class)->deactivate($this->managerEmployee, $this->chro, 'Leave');

    expect($this->manager->fresh()->can('candidates.viewAny'))->toBeFalse()
        ->and($this->manager->fresh()->can('view', CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterEmployee->id])))->toBeFalse();
});
