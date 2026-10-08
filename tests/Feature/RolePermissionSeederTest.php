<?php

use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
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
    // while spatie teams are already on; `migrate` runs outside any tenant. MySQL rejects a query
    // naming the missing column, but SQLite reads an unknown "tenant_id" as a string literal, so
    // the queries are checked instead.
    $defaultConnection = DB::getDefaultConnection();
    config(['database.connections.pre_tenancy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('pre_tenancy');
    $queries = [];

    try {
        (require database_path('migrations/2026_08_26_100131_create_permission_tables.php'))->up();
        DB::table('roles')->insert([['name' => 'chro', 'guard_name' => 'web'], ['name' => 'recruiter', 'guard_name' => 'web']]);
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        TenantContext::current()->runWithoutTenant(fn () => (require database_path('migrations/2026_09_25_135221_grant_phase_four_permissions.php'))->up());

        expect(Schema::hasColumn('roles', 'tenant_id'))->toBeFalse()
            ->and(array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'tenant_id'))))->toBe([])
            ->and(Role::query()->where('name', 'employee')->sole()->permissions()->pluck('name')->all())
            ->toBe(RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS['employee'])
            ->and(Role::query()->where('name', 'recruiter')->sole()->permissions()->pluck('name')->sort()->values()->all())
            ->toBe(collect(RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS['recruiter'])->sort()->values()->all());
    } finally {
        DB::setDefaultConnection($defaultConnection);
    }
});

/**
 * Runs $callback against a database holding only the permission tables, shaped as an upgraded
 * database has them when it reaches the Phase 4 migration (no roles.tenant_id, no roles.key), and
 * outside any tenant, as `migrate` runs.
 */
function onPhaseFourPreTenancyDatabase(Closure $callback): mixed
{
    $defaultConnection = DB::getDefaultConnection();
    config(['database.connections.pre_tenancy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge('pre_tenancy');
    DB::setDefaultConnection('pre_tenancy');

    try {
        (require database_path('migrations/2026_08_26_100131_create_permission_tables.php'))->up();

        return TenantContext::current()->runWithoutTenant($callback);
    } finally {
        DB::setDefaultConnection($defaultConnection);
    }
}

function runPhaseFourPermissionMigration(): void
{
    (require database_path('migrations/2026_09_25_135221_grant_phase_four_permissions.php'))->up();
}

/**
 * @return list<string>
 */
function phaseFourRolePermissionNames(string $roleName): array
{
    return DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', $roleName)
        ->orderBy('permissions.name')
        ->pluck('permissions.name')
        ->all();
}

test('the phase 4 permission migration completes an interrupted upgrade without duplicating roles, permissions or grants', function (): void {
    $state = onPhaseFourPreTenancyDatabase(function (): array {
        DB::table('roles')->insert(collect(['chro', 'vp_hr', 'manager', 'assistant_manager', 'recruiter', 'hiring_partner'])
            ->map(fn (string $name): array => ['name' => $name, 'guard_name' => 'web'])->all());
        // Client #1: the first attempt created the permissions and granted the first four roles,
        // then stopped at the employee role.
        foreach (['pipeline.configure', 'pipeline.override', 'talent-pools.viewAny', 'talent-pools.manage', 'referrals.submit', 'referrals.review', 'candidates.override-duplicate', 'portal.manage', 'interview-slots.manage'] as $name) {
            DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'web']);
        }
        DB::table('role_has_permissions')->insert(['role_id' => 5, 'permission_id' => DB::table('permissions')->where('name', 'portal.manage')->value('id')]);

        runPhaseFourPermissionMigration();
        runPhaseFourPermissionMigration();

        return [
            'roles' => DB::table('roles')->orderBy('id')->pluck('name', 'id')->all(),
            'permissions' => DB::table('permissions')->count(),
            'grants' => DB::table('role_has_permissions')->count(),
            'employee' => phaseFourRolePermissionNames('employee'),
            'recruiter' => phaseFourRolePermissionNames('recruiter'),
            'chro' => count(phaseFourRolePermissionNames('chro')),
            'hiring_partner' => phaseFourRolePermissionNames('hiring_partner'),
        ];
    });

    expect($state)->toBe([
        'roles' => [1 => 'chro', 2 => 'vp_hr', 3 => 'manager', 4 => 'assistant_manager', 5 => 'recruiter', 6 => 'hiring_partner', 7 => 'employee'],
        'permissions' => 9,
        'grants' => 9 + 8 + 6 + 5 + 1 + 9,
        'employee' => ['referrals.submit'],
        'recruiter' => ['interview-slots.manage', 'portal.manage', 'referrals.review', 'referrals.submit', 'talent-pools.viewAny'],
        'chro' => 9,
        'hiring_partner' => [],
    ]);
});

test('the phase 4 permission migration keeps the id and grants of an employee role that already exists', function (): void {
    $state = onPhaseFourPreTenancyDatabase(function (): array {
        DB::table('roles')->insert([['name' => 'chro', 'guard_name' => 'web'], ['name' => 'recruiter', 'guard_name' => 'web'], ['name' => 'employee', 'guard_name' => 'web']]);
        DB::table('permissions')->insert(['name' => 'candidates.view', 'guard_name' => 'web']);
        DB::table('role_has_permissions')->insert(['role_id' => 3, 'permission_id' => 1]);

        runPhaseFourPermissionMigration();

        return [
            'employee roles' => DB::table('roles')->where('name', 'employee')->pluck('id')->all(),
            'employee' => phaseFourRolePermissionNames('employee'),
        ];
    });

    expect($state)->toBe([
        'employee roles' => [3],
        'employee' => ['candidates.view', 'referrals.submit'],
    ]);
});

test('the phase 4 permission migration creates no role on a fresh install', function (): void {
    $state = onPhaseFourPreTenancyDatabase(function (): array {
        runPhaseFourPermissionMigration();

        return ['roles' => DB::table('roles')->count(), 'permissions' => DB::table('permissions')->count()];
    });

    expect($state)->toBe(['roles' => 0, 'permissions' => 9]);
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
