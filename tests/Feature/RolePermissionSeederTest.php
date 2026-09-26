<?php

use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('seeder creates every role with the expected default permissions', function (): void {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(['assistant_manager', 'chro', 'employee', 'manager', 'recruiter', 'vp_hr']);

    $chro = Role::findByName('chro');
    expect($chro->permissions()->count())->toBe(Permission::count());

    $recruiter = Role::findByName('recruiter');
    expect($recruiter->hasPermissionTo('candidates.create'))->toBeTrue();
    expect($recruiter->hasPermissionTo('incentives.approve'))->toBeFalse();
    expect($recruiter->hasPermissionTo('hierarchy.view-all'))->toBeFalse();

    expect(Role::findByName('employee')->permissions()->pluck('name')->all())->toBe(['referrals.submit']);
});

test('seeder is idempotent and can be re-run safely', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->count())->toBe(6);
});

test('the phase 6 permission migration grants automation permissions without removing custom role edits', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $manager = Role::findByName('manager');
    $manager->revokePermissionTo('automation.activate');
    $manager->givePermissionTo('reports.export');
    Permission::findOrCreate('custom.permission');
    $manager->givePermissionTo('custom.permission');

    (require database_path('migrations/2026_09_26_000002_grant_phase_six_permissions.php'))->up();

    expect($manager->fresh()->hasPermissionTo('automation.activate'))->toBeTrue()
        ->and($manager->fresh()->hasPermissionTo('custom.permission'))->toBeTrue()
        ->and($manager->fresh()->hasPermissionTo('automation.organization'))->toBeFalse()
        ->and(Role::findByName('recruiter')->hasPermissionTo('automation.view'))->toBeFalse()
        ->and(Role::findByName('chro')->hasPermissionTo('automation.organization'))->toBeTrue();
});

test('the phase 7 permission migration grants intelligence permissions additively', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $recruiter = Role::findByName('recruiter');
    $recruiter->revokePermissionTo('intelligence.view');

    (require database_path('migrations/2026_09_26_000003_grant_phase_seven_permissions.php'))->up();

    expect($recruiter->fresh()->hasPermissionTo('intelligence.view'))->toBeTrue()
        ->and($recruiter->fresh()->hasPermissionTo('intelligence.memory.view'))->toBeFalse()
        ->and(Role::findByName('manager')->hasPermissionTo('intelligence.role-dna.manage'))->toBeTrue()
        ->and(Role::findByName('chro')->hasPermissionTo('intelligence.memory.manage'))->toBeTrue();
});
