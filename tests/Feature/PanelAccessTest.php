<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a user with no role cannot access the admin panel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('a user with a recruitment role can access the admin panel', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});
