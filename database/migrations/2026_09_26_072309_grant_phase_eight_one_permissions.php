<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Additively grants the Phase 8.1 permission (ai.conversations.view — reviewing other users'
     * AI conversations and action logs, hierarchy-scoped) to existing roles. Never syncs, so
     * customised roles keep everything they have. Fresh installs get it from RolePermissionSeeder.
     */
    public function up(): void
    {
        $all = array_unique(array_merge(...array_values(RolePermissionSeeder::PHASE_8_1_ROLE_PERMISSIONS)));

        foreach ($all as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (RolePermissionSeeder::PHASE_8_1_ROLE_PERMISSIONS as $roleName => $permissions) {
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
