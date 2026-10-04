<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Frozen copy of the SaaS-4 tenant billing permissions.
     */
    private const PERMISSIONS = ['billing.view', 'billing.manage'];

    /**
     * SaaS-4 (backfill + validate): billing.view (the organisation's subscription, invoices and
     * payments) and billing.manage (billing details and contact; cancelling or resuming at period
     * end) exist, and every tenant's CHRO role (its immutable key) holds both — CHRO holds every
     * permission. No other role receives them: a tenant administrator is not a billing
     * administrator by default. Additive: never syncs, never removes. Fresh installs get them from
     * RolePermissionSeeder. Refuses to finish unless every CHRO role holds both.
     */
    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $name) {
            if (DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->doesntExist()) {
                DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $permissionIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->pluck('id');
        $chroRoleIds = DB::table('roles')->where('key', 'chro')->pluck('id');

        foreach ($chroRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->doesntExist()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }

        $missing = DB::table('roles')->where('key', 'chro')
            ->whereRaw('(select count(*) from role_has_permissions where role_has_permissions.role_id = roles.id and role_has_permissions.permission_id in ('.implode(',', $permissionIds->map(fn ($id): int => (int) $id)->all() ?: [0]).')) < ?', [count(self::PERMISSIONS)])
            ->count();

        if ($missing > 0) {
            throw new RuntimeException("{$missing} CHRO role(s) did not receive the billing permissions.");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
