<?php

use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('seeder creates every role with the expected default permissions', function (): void {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(['assistant_manager', 'chro', 'manager', 'recruiter', 'vp_hr']);

    $chro = Role::findByName('chro');
    expect($chro->permissions()->count())->toBe(Permission::count());

    $recruiter = Role::findByName('recruiter');
    expect($recruiter->hasPermissionTo('candidates.create'))->toBeTrue();
    expect($recruiter->hasPermissionTo('incentives.approve'))->toBeFalse();
    expect($recruiter->hasPermissionTo('hierarchy.view-all'))->toBeFalse();
});

test('seeder is idempotent and can be re-run safely', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->count())->toBe(5);
});
