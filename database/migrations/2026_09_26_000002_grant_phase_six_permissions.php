<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Additively grants the Phase 6 (automation & action) permissions to existing roles (never syncs, so customised
     * roles keep everything they have). Fresh installs get them from RolePermissionSeeder.
     */
    public function up(): void
    {
        $all = array_unique(array_merge(...array_values(RolePermissionSeeder::PHASE_6_ROLE_PERMISSIONS)));

        foreach ($all as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (RolePermissionSeeder::PHASE_6_ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->first()?->givePermissionTo($permissions);
        }

        Role::query()->where('name', 'chro')->first()?->givePermissionTo($all);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
