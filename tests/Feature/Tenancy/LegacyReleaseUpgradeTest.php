<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Client #1: a database at the pre-SaaS release line (9cba8e3: 75 migrations, spatie teams off, no
 * employee role) upgrades through every later migration in one `migrate`, as production runs it —
 * a fresh process, outside any tenant. Its roles, role assignments, logins and reporting lines
 * survive unchanged and end up on Tenant #1.
 */
const LEGACY_RELEASE_LAST_MIGRATION = '2026_09_14_124342_create_interviewers_table';

/**
 * An empty throwaway database on the suite's own driver, as the `legacy_release` connection. A MySQL
 * run upgrades a MySQL database: SQLite reads an unknown double-quoted column as a string literal,
 * so only MySQL rejects a query naming a column the schema does not have yet. The database is
 * created through its own connection, so the DDL never commits the test's wrapping transaction.
 *
 * @return array{0: array<string, string>, 1: Closure(): void} the environment for `migrate`, and the cleanup
 */
function legacyReleaseDatabase(): array
{
    if (DB::connection()->getDriverName() === 'mysql') {
        $name = DB::connection()->getDatabaseName().'_legacy_upgrade';
        config(['database.connections.legacy_release_server' => config('database.connections.mysql')]);
        $server = DB::connection('legacy_release_server');
        $server->statement("DROP DATABASE IF EXISTS `{$name}`");
        $server->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.legacy_release' => [...config('database.connections.mysql'), 'database' => $name]]);

        return [['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $name], fn () => $server->statement("DROP DATABASE IF EXISTS `{$name}`")];
    }

    $file = tempnam(sys_get_temp_dir(), 'legacy-release-');
    config(['database.connections.legacy_release' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);

    return [['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file], fn () => @unlink($file)];
}

/**
 * @param  array<string, string>  $database  the environment naming the database
 * @param  list<string>  $paths  migration files to run, or every pending migration when empty
 */
function legacyReleaseMigrate(array $database, array $paths = []): void
{
    $result = Process::path(base_path())
        ->timeout(600)
        ->env([...$database, 'APP_ENV' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'])
        ->run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction', ...($paths === [] ? [] : ['--realpath', ...array_map(fn (string $path): string => '--path='.$path, $paths)])]);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

test('an organisation on the pre-SaaS release upgrades through every migration keeping its roles, assignments and reporting lines on Tenant #1', function (): void {
    [$database, $dropDatabase] = legacyReleaseDatabase();
    $migrations = glob(database_path('migrations/*.php'));
    sort($migrations);
    $legacyRelease = array_slice($migrations, 0, 75);

    try {
        expect(basename(end($legacyRelease), '.php'))->toBe(LEGACY_RELEASE_LAST_MIGRATION);
        legacyReleaseMigrate($database, $legacyRelease);

        $legacy = DB::connection('legacy_release');
        // The release seeded a fixed permission list (no screen deletes one); the Phase 8.5 migration
        // reads offers.manage and performance.view from it.
        $legacy->table('permissions')->insert(collect(['candidates.view', 'reports.export', 'offers.manage', 'performance.view'])
            ->map(fn (string $name): array => ['name' => $name, 'guard_name' => 'web'])->all());
        $legacy->table('roles')->insert(collect(['chro', 'vp_hr', 'manager', 'assistant_manager', 'recruiter', 'hiring_partner'])
            ->map(fn (string $name): array => ['name' => $name, 'guard_name' => 'web'])->all());
        $legacy->table('role_has_permissions')->insert([['role_id' => 5, 'permission_id' => 2], ['role_id' => 6, 'permission_id' => 1]]);
        $legacy->table('departments')->insert(['name' => 'Talent Acquisition', 'code' => 'TA']);
        $legacy->table('designations')->insert(['name' => 'Recruiter', 'code' => 'DSG-RCT', 'department_id' => 1]);
        $legacy->table('employees')->insert(collect([[1, null], [2, 1], [3, 2]])->map(fn (array $row): array => [
            'employee_code' => sprintf('EMP-%06d', $row[0]), 'first_name' => 'Person', 'last_name' => (string) $row[0], 'email' => "person{$row[0]}@client.test",
            'department_id' => 1, 'designation_id' => 1, 'reports_to_id' => $row[1],
        ])->all());
        $legacy->table('employee_hierarchy')->insert([
            ['ancestor_id' => 1, 'descendant_id' => 1, 'depth' => 0], ['ancestor_id' => 2, 'descendant_id' => 2, 'depth' => 0], ['ancestor_id' => 3, 'descendant_id' => 3, 'depth' => 0],
            ['ancestor_id' => 1, 'descendant_id' => 2, 'depth' => 1], ['ancestor_id' => 2, 'descendant_id' => 3, 'depth' => 1], ['ancestor_id' => 1, 'descendant_id' => 3, 'depth' => 2],
        ]);
        $legacy->table('users')->insert(collect([1 => 1, 2 => 2, 3 => 3, 4 => null])->map(fn (?int $employeeId, int $id): array => [
            'name' => "Login {$id}", 'email' => "login{$id}@client.test", 'password' => 'not-a-hash', 'employee_id' => $employeeId,
        ])->values()->all());
        $legacy->table('model_has_roles')->insert(collect([[1, 1], [3, 2], [5, 3], [6, 3], [2, 4]])->map(fn (array $row): array => ['role_id' => $row[0], 'model_type' => 'App\Models\User', 'model_id' => $row[1]])->all());
        $legacy->table('model_has_permissions')->insert(['permission_id' => 2, 'model_type' => 'App\Models\User', 'model_id' => 3]);
        DB::purge('legacy_release');

        legacyReleaseMigrate($database);

        $upgraded = DB::connection('legacy_release');
        $tenantId = $upgraded->table('tenants')->sole()->id;
        $rolePermissions = fn (string $role): array => $upgraded->table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)->orderBy('permissions.name')->pluck('permissions.name')->all();

        expect($upgraded->table('migrations')->count())->toBe(count($migrations));
        expect($upgraded->table('roles')->orderBy('id')->get(['id', 'name', 'key', 'tenant_id'])->map(fn ($role): array => (array) $role)->all())->toBe([
            ['id' => 1, 'name' => 'chro', 'key' => 'chro', 'tenant_id' => $tenantId],
            ['id' => 2, 'name' => 'vp_hr', 'key' => 'vp_hr', 'tenant_id' => $tenantId],
            ['id' => 3, 'name' => 'manager', 'key' => 'manager', 'tenant_id' => $tenantId],
            ['id' => 4, 'name' => 'assistant_manager', 'key' => 'assistant_manager', 'tenant_id' => $tenantId],
            ['id' => 5, 'name' => 'recruiter', 'key' => 'recruiter', 'tenant_id' => $tenantId],
            ['id' => 6, 'name' => 'hiring_partner', 'key' => null, 'tenant_id' => $tenantId],
            ['id' => 7, 'name' => 'employee', 'key' => 'employee', 'tenant_id' => $tenantId],
        ]);
        expect($rolePermissions('employee'))->toBe(['referrals.submit']);
        expect($rolePermissions('hiring_partner'))->toBe(['candidates.view']);
        expect($rolePermissions('recruiter'))->toContain('reports.export', 'portal.manage');
        expect($upgraded->table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get(['tenant_id', 'role_id', 'model_id'])->map(fn ($row): array => array_values((array) $row))->all())
            ->toBe([[$tenantId, 1, 1], [$tenantId, 3, 2], [$tenantId, 5, 3], [$tenantId, 6, 3], [$tenantId, 2, 4]]);
        expect($upgraded->table('model_has_permissions')->get(['tenant_id', 'permission_id', 'model_id'])->map(fn ($row): array => array_values((array) $row))->all())
            ->toBe([[$tenantId, 2, 3]]);
        expect($upgraded->table('tenant_memberships')->orderBy('user_id')->get(['tenant_id', 'user_id', 'employee_id', 'status'])->map(fn ($row): array => array_values((array) $row))->all())
            ->toBe([[$tenantId, 1, 1, 'active'], [$tenantId, 2, 2, 'active'], [$tenantId, 3, 3, 'active'], [$tenantId, 4, null, 'active']]);
        expect($upgraded->table('employees')->orderBy('id')->get(['id', 'reports_to_id', 'tenant_id'])->map(fn ($row): array => array_values((array) $row))->all())
            ->toBe([[1, null, $tenantId], [2, 1, $tenantId], [3, 2, $tenantId]]);
        expect($upgraded->table('employee_hierarchy')->orderBy('ancestor_id')->orderBy('descendant_id')->get(['ancestor_id', 'descendant_id', 'depth'])->map(fn ($row): array => array_values((array) $row))->all())
            ->toBe([[1, 1, 0], [1, 2, 1], [1, 3, 2], [2, 2, 0], [2, 3, 1], [3, 3, 0]]);
    } finally {
        DB::purge('legacy_release');
        $dropDatabase();
    }
});
