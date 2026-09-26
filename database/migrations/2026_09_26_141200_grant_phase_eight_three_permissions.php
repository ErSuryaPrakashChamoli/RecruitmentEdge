<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Additively grants the Phase 8.3 permission employees.convert (converting a joined candidate
     * into an employee) to VP HR and CHRO. Never syncs, so customised roles keep everything they
     * have. Fresh installs get it from RolePermissionSeeder.
     */
    public function up(): void
    {
        Permission::findOrCreate('employees.convert');

        foreach (RolePermissionSeeder::PHASE_8_3_ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->first()?->givePermissionTo($permissions);
        }

        Role::query()->where('name', 'chro')->first()?->givePermissionTo('employees.convert');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
