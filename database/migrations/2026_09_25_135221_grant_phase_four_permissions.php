<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Additively grants the Phase 4 permissions to roles that already exist. Never syncs — any role
     * an administrator has customised keeps every permission it already has. On a fresh install
     * the roles don't exist yet and RolePermissionSeeder grants these instead.
     */
    public function up(): void
    {
        foreach (RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS as $permissions) {
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission);
            }
        }

        foreach (RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->first();

            if ($role === null && $roleName === 'employee' && Role::query()->exists()) {
                $role = Role::findOrCreate('employee');
            }

            $role?->givePermissionTo($permissions);
        }

        $chro = Role::query()->where('name', 'chro')->first();
        $chro?->givePermissionTo(array_unique(array_merge(...array_values(RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS))));

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
};
