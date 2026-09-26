<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Additively grants outcomes.review (reviewing organisation-wide Outcome Loop learning insights)
     * to VP HR and CHRO. Never syncs, so customised roles keep everything they have. Fresh installs
     * get it from RolePermissionSeeder.
     */
    public function up(): void
    {
        Permission::findOrCreate('outcomes.review');

        foreach (['vp_hr', 'chro'] as $roleName) {
            Role::query()->where('name', $roleName)->first()?->givePermissionTo('outcomes.review');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
