<?php

use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Phase 8.5 (D22, SEC-2): compensation figures (offered CTC in analytics, the offer table and the
     * offer export) move behind their own permission, compensation.view. To keep today's access
     * exactly as it is, every role that can currently see them — it holds both offers.manage and
     * performance.view — receives it, whatever its name, plus CHRO (found by its immutable key).
     * Additive: never syncs, never removes anything. Fresh installs get it from RolePermissionSeeder.
     */
    public function up(): void
    {
        Permission::findOrCreate('compensation.view');

        Role::query()->get()
            ->filter(fn (Role $role) => $role->hasPermissionTo('offers.manage') && $role->hasPermissionTo('performance.view'))
            ->each(fn (Role $role) => $role->givePermissionTo('compensation.view'));

        foreach (RolePermissionSeeder::PHASE_8_5_ROLE_PERMISSIONS as $roleKey => $granted) {
            Role::byKey($roleKey)?->givePermissionTo($granted);
        }

        Role::byKey('chro')?->givePermissionTo('compensation.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
