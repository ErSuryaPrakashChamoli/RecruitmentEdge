<?php

use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Frozen copy of RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS as of Phase 4.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'vp_hr' => [
            'pipeline.configure', 'pipeline.override', 'talent-pools.viewAny', 'talent-pools.manage',
            'referrals.submit', 'referrals.review', 'candidates.override-duplicate', 'portal.manage', 'interview-slots.manage',
        ],
        'manager' => [
            'pipeline.override', 'talent-pools.viewAny', 'talent-pools.manage',
            'referrals.submit', 'referrals.review', 'candidates.override-duplicate', 'portal.manage', 'interview-slots.manage',
        ],
        'assistant_manager' => [
            'talent-pools.viewAny', 'talent-pools.manage',
            'referrals.submit', 'referrals.review', 'portal.manage', 'interview-slots.manage',
        ],
        'recruiter' => [
            'talent-pools.viewAny', 'referrals.submit', 'referrals.review', 'portal.manage', 'interview-slots.manage',
        ],
        'employee' => ['referrals.submit'],
    ];

    /**
     * Additively grants the Phase 4 permissions to roles that already exist, and gives an existing
     * organisation the employee role. Never syncs — any role an administrator has customised keeps
     * every permission it already has. On a fresh install the roles don't exist yet and
     * RolePermissionSeeder grants these instead.
     *
     * Query builder only, against the roles table as it is at this point (no tenant_id, no key): an
     * upgraded database reaches this migration long before SaaS-1 adds roles.tenant_id
     * (2026_10_04_025113), while spatie's models run with teams on and filter role lookups by
     * tenant_id. Existing roles keep their ids; the Tenant #1 backfill assigns them, and the employee
     * role created here, later. Re-running after an interruption only adds what is still missing.
     */
    public function up(): void
    {
        $now = now();
        $permissionIds = [];

        foreach (self::ROLE_PERMISSIONS as $permissions) {
            foreach ($permissions as $name) {
                $permissionIds[$name] ??= $this->permissionId($name, $now);
            }
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $roleId = $this->roleId($roleName);

            if ($roleId === null && $roleName === 'employee' && DB::table('roles')->exists()) {
                $roleId = (int) DB::table('roles')->insertGetId(['name' => 'employee', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            }

            if ($roleId !== null) {
                $this->grant($roleId, array_map(fn (string $name): int => $permissionIds[$name], $permissions));
            }
        }

        $chroId = $this->roleId('chro');

        if ($chroId !== null) {
            $this->grant($chroId, array_values($permissionIds));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Permissions are left in place on rollback: removing them could strip grants an
     * administrator made deliberately after this migration ran.
     */
    public function down(): void
    {
        //
    }

    private function permissionId(string $name, CarbonInterface $now): int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

        return (int) ($id ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]));
    }

    private function roleId(string $name): ?int
    {
        $id = DB::table('roles')->where('name', $name)->where('guard_name', 'web')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  list<int>  $permissionIds
     */
    private function grant(int $roleId, array $permissionIds): void
    {
        $held = DB::table('role_has_permissions')->where('role_id', $roleId)->pluck('permission_id')->map(fn ($id): int => (int) $id)->all();

        $missing = array_values(array_diff(array_unique($permissionIds), $held));

        if ($missing !== []) {
            DB::table('role_has_permissions')->insert(array_map(fn (int $permissionId): array => ['role_id' => $roleId, 'permission_id' => $permissionId], $missing));
        }
    }
};
