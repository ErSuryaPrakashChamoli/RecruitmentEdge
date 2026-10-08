<?php

use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Additively grants the Phase 8.4 permissions (users.access.manage, access.review,
     * employees.separation.cancel) to VP HR and CHRO. Roles are found by their immutable key, never
     * by display name, so a renamed CHRO role still receives them. Never syncs, so customised roles
     * keep everything they have. Fresh installs get them from RolePermissionSeeder.
     */
    public function up(): void
    {
        $permissions = ['users.access.manage', 'access.review', 'employees.separation.cancel'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (RolePermissionSeeder::PHASE_8_4_ROLE_PERMISSIONS as $roleKey => $granted) {
            Role::byKey($roleKey)?->givePermissionTo($granted);
        }

        Role::byKey('chro')?->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
