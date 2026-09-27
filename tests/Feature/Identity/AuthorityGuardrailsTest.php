<?php

use App\Events\UserRoleChanged;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\AuthorityGuard;
use App\Services\Identity\IdentityProvisioningService;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\StaffAccessService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->rootEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->rootEmployee->id])->assignRole('chro');
    // A delegated user administrator: users.manage and a team, but not CHRO.
    $this->adminEmployee = Employee::factory()->reportingTo($this->rootEmployee)->create();
    $this->userAdmin = User::factory()->create(['employee_id' => $this->adminEmployee->id])
        ->assignRole(Role::create(['name' => 'user_admin'])->givePermissionTo(['users.manage', 'pipeline.transition']));
    $this->teamMember = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->adminEmployee)->create()->id])->assignRole('employee');
    $this->roles = app(RoleAssignmentService::class);
});

function guardrailRoleId(string $key): int
{
    return Role::byKeyOrFail($key)->id;
}

test('a users.manage holder cannot grant themselves CHRO or any other role', function (): void {
    expect(fn () => $this->roles->syncUserRoles($this->userAdmin, [guardrailRoleId('chro')], $this->userAdmin))->toThrow(DomainException::class, 'your own roles')
        ->and($this->userAdmin->fresh()->hasRole('chro'))->toBeFalse();
});

test('a protected role is granted only by one of its holders, and the refusal is audited', function (): void {
    expect(fn () => $this->roles->syncUserRoles($this->teamMember, [guardrailRoleId('chro')], $this->userAdmin))->toThrow(DomainException::class, 'Only a holder')
        ->and($this->teamMember->fresh()->hasRole('chro'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'protected_role_refused')->exists())->toBeTrue();
});

test('nobody can grant a role carrying permissions they do not hold', function (): void {
    expect(fn () => $this->roles->syncUserRoles($this->teamMember, [guardrailRoleId('vp_hr')], $this->userAdmin))->toThrow(DomainException::class, 'permissions you do not hold')
        ->and($this->teamMember->fresh()->hasRole('vp_hr'))->toBeFalse();
});

test('an authorised grant is audited with old and new roles and announced after commit', function (): void {
    Event::fake([UserRoleChanged::class]);

    $this->roles->syncUserRoles($this->teamMember, [guardrailRoleId('employee'), Role::findByName('user_admin')->id], $this->chro);

    $audit = AuditLog::query()->where('action', 'roles_changed')->where('auditable_id', $this->teamMember->id)->sole();

    expect($this->teamMember->fresh()->getRoleNames()->sort()->values()->all())->toBe(['employee', 'user_admin'])
        ->and($audit->old_values)->toBe(['roles' => ['employee']])
        ->and($audit->getAttribute('changes')['roles'])->toBe(['employee', 'user_admin'])
        ->and($audit->user_id)->toBeNull();

    Event::assertDispatched(UserRoleChanged::class, fn (UserRoleChanged $event) => $event->userId === $this->teamMember->id && $event->actorId === $this->chro->id);
});

test('roles are only assigned inside your hierarchy, and never to a revoked login', function (): void {
    $outsider = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('employee');

    expect(fn () => $this->roles->syncUserRoles($outsider, [], $this->userAdmin))->toThrow(DomainException::class, 'outside your hierarchy');

    app(StaffAccessService::class)->revoke($this->teamMember, $this->chro, 'Left');

    expect(fn () => $this->roles->syncUserRoles($this->teamMember->fresh(), [guardrailRoleId('employee')], $this->chro))->toThrow(DomainException::class, 'revoked');
});

test('nobody can relink their own login to another employee to widen what they see', function (): void {
    expect(fn () => app(IdentityProvisioningService::class)->linkEmployee($this->userAdmin, $this->rootEmployee->id, $this->userAdmin))->toThrow(DomainException::class, 'your own login')
        ->and($this->userAdmin->fresh()->employee_id)->toBe($this->adminEmployee->id);
});

test('a login is linked only to a current employee in scope without another login', function (): void {
    $spare = User::factory()->create()->assignRole('employee');
    $inactive = Employee::factory()->reportingTo($this->adminEmployee)->create(['status' => 'inactive']);
    $taken = Employee::factory()->reportingTo($this->adminEmployee)->create();
    User::factory()->create(['employee_id' => $taken->id]);
    $outside = Employee::factory()->create();
    $provision = app(IdentityProvisioningService::class);

    expect(fn () => $provision->linkEmployee($spare, $inactive->id, $this->chro))->toThrow(DomainException::class, 'current (active) employee')
        ->and(fn () => $provision->linkEmployee($spare, $taken->id, $this->chro))->toThrow(DomainException::class, 'already has a login')
        ->and(fn () => $provision->linkEmployee($this->teamMember, $outside->id, $this->userAdmin))->toThrow(DomainException::class, 'outside your hierarchy');

    $free = Employee::factory()->reportingTo($this->adminEmployee)->create();
    $provision->linkEmployee($this->teamMember, $free->id, $this->userAdmin);

    expect($this->teamMember->fresh()->employee_id)->toBe($free->id)
        ->and(AuditLog::query()->where('action', 'employee_linked')->where('auditable_id', $this->teamMember->id)->exists())->toBeTrue();
});

test('the employee link cannot be written outside the provisioning service', function (): void {
    expect(fn () => $this->teamMember->update(['employee_id' => $this->rootEmployee->id]))->toThrow(LogicException::class);
});

test('a roles.manage holder cannot give their own role, or any role, permissions they lack', function (): void {
    $roleAdminRole = Role::create(['name' => 'role_admin'])->givePermissionTo(['roles.manage', 'reports.export']);
    $roleAdmin = User::factory()->create()->assignRole($roleAdminRole);
    $other = Role::create(['name' => 'helpers']);
    $usersManage = Permission::findByName('users.manage')->id;

    expect(fn () => $this->roles->updateRole($roleAdminRole, 'role_admin', [$usersManage], $roleAdmin))->toThrow(DomainException::class, 'a role you hold')
        ->and(fn () => $this->roles->updateRole($other, 'helpers', [$usersManage], $roleAdmin))->toThrow(DomainException::class, 'do not hold')
        ->and(fn () => $this->roles->createRole('escalate', [$usersManage], $roleAdmin))->toThrow(DomainException::class, 'do not hold')
        ->and($roleAdminRole->fresh()->hasPermissionTo('users.manage'))->toBeFalse();
});

test('renaming the CHRO role keeps its protection; it still cannot be deleted', function (): void {
    $roleAdmin = User::factory()->create()->assignRole(Role::create(['name' => 'role_admin'])->givePermissionTo(['roles.manage']));
    $chroRole = Role::byKeyOrFail('chro');

    $this->roles->updateRole($chroRole, 'Chief People Officer', $chroRole->permissions()->pluck('id')->all(), $roleAdmin);

    expect($chroRole->fresh()->key)->toBe('chro')
        ->and($chroRole->fresh()->is_protected)->toBeTrue()
        ->and(fn () => $this->roles->deleteRole($chroRole->fresh(), $roleAdmin))->toThrow(DomainException::class, 'protected')
        ->and(fn () => $chroRole->fresh()->delete())->toThrow(DomainException::class, 'protected')
        ->and(fn () => $chroRole->fresh()->forceFill(['key' => 'other'])->save())->toThrow(DomainException::class, 'cannot be changed')
        ->and(Role::byKey('chro'))->not->toBeNull();
});

test('a protected role\'s permissions cannot be edited, and recreating a role by its old name grants no CHRO authority', function (): void {
    $roleAdmin = User::factory()->create()->assignRole(Role::create(['name' => 'role_admin'])->givePermissionTo(['roles.manage', 'reports.export']));
    $chroRole = Role::byKeyOrFail('chro');

    expect(fn () => $this->roles->updateRole($chroRole, $chroRole->name, [], $roleAdmin))->toThrow(DomainException::class, 'protected');

    $this->roles->updateRole($chroRole, 'Chief People Officer', $chroRole->permissions()->pluck('id')->all(), $roleAdmin);
    $impostor = $this->roles->createRole('chro', [], $roleAdmin);
    $this->teamMember->assignRole($impostor);

    expect($impostor->key)->toBeNull()
        ->and($impostor->is_protected)->toBeFalse()
        ->and(app(AuthorityGuard::class)->effectiveChroIds()->all())->toBe([$this->chro->id]);
});

test('nobody edits or deletes a role they hold', function (): void {
    expect(fn () => $this->roles->updateRole(Role::byKeyOrFail('chro'), 'chro', Role::byKeyOrFail('chro')->permissions()->pluck('id')->all(), $this->chro))->toThrow(DomainException::class, 'a role you hold')
        ->and($this->chro->can('update', Role::byKeyOrFail('chro')))->toBeFalse()
        ->and($this->chro->can('delete', Role::byKeyOrFail('chro')))->toBeFalse()
        ->and($this->chro->can('delete', Role::byKeyOrFail('recruiter')))->toBeTrue();
});

test('deleting a role is audited with what it granted', function (): void {
    $role = Role::create(['name' => 'temporary'])->givePermissionTo('reports.export');

    $this->roles->deleteRole($role, $this->chro);

    $audit = AuditLog::query()->where('action', 'deleted')->where('auditable_type', Role::class)->sole();

    expect($audit->old_values)->toMatchArray(['name' => 'temporary', 'permissions' => ['reports.export']]);
});

test('the Users screen never lets an administrator edit their own login and has no delete', function (): void {
    actingAs($this->userAdmin);

    get("/admin/users/{$this->userAdmin->id}/edit")->assertForbidden();
    expect($this->userAdmin->can('delete', $this->teamMember))->toBeFalse();
});

test('a tampered role list on the Users screen is refused by the service', function (): void {
    actingAs($this->userAdmin);

    Livewire::test(EditUser::class, ['record' => $this->teamMember->getRouteKey()])
        ->set('data.roles', [(string) guardrailRoleId('chro')])
        ->call('save');

    expect($this->teamMember->fresh()->hasRole('chro'))->toBeFalse()
        ->and($this->teamMember->fresh()->hasRole('employee'))->toBeTrue();
});

test('a tampered permission list on a protected role is refused by the service', function (): void {
    $roleAdmin = User::factory()->create()->assignRole(Role::create(['name' => 'role_admin'])->givePermissionTo(['roles.manage']));
    actingAs($roleAdmin);
    $chroRole = Role::byKeyOrFail('chro');
    $before = $chroRole->permissions()->count();

    Livewire::test(EditRole::class, ['record' => $chroRole->getRouteKey()])
        ->set('data.permissions', [])
        ->call('save');

    expect($chroRole->fresh()->permissions()->count())->toBe($before);
});

test('holding every CHRO permission still does not let you grant the protected CHRO role', function (): void {
    $superAdmin = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->rootEmployee)->create()->id])
        ->assignRole(Role::create(['name' => 'all_but_chro'])->givePermissionTo(Role::byKeyOrFail('chro')->permissions));

    expect(fn () => $this->roles->syncUserRoles($this->teamMember, [guardrailRoleId('chro')], $superAdmin))->toThrow(DomainException::class, 'Only a holder')
        ->and($this->teamMember->fresh()->hasRole('chro'))->toBeFalse();
});
