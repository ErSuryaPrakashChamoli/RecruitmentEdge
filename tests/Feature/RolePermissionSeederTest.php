<?php

use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

test('the phase 4 permission migration upgrades roles that have no tenant_id yet', function (): void {
    // An upgraded database reaches this migration before 2026_10_04_025113 adds roles.tenant_id,
    // while spatie teams are already on; `migrate` runs outside any tenant.
    $defaultConnection = DB::getDefaultConnection();
    config(['database.connections.pre_tenancy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('pre_tenancy');

    try {
        (require database_path('migrations/2026_08_26_100131_create_permission_tables.php'))->up();
        DB::table('roles')->insert([['name' => 'chro', 'guard_name' => 'web'], ['name' => 'recruiter', 'guard_name' => 'web']]);

        TenantContext::current()->runWithoutTenant(fn () => (require database_path('migrations/2026_09_25_135221_grant_phase_four_permissions.php'))->up());

        expect(Schema::hasColumn('roles', 'tenant_id'))->toBeFalse()
            ->and(Role::query()->where('name', 'employee')->sole()->permissions()->pluck('name')->all())
            ->toBe(RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS['employee'])
            ->and(Role::query()->where('name', 'recruiter')->sole()->permissions()->pluck('name')->sort()->values()->all())
            ->toBe(collect(RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS['recruiter'])->sort()->values()->all());
    } finally {
        DB::setDefaultConnection($defaultConnection);
    }
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
