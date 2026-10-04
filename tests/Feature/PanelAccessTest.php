<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a user with no role cannot access the admin panel', function (): void {
    $user = User::factory()->create();

    // SaaS-2: an identity that can enter no tenant is signed out (no 403 page inside a session).
    $this->actingAs($user)->get('/admin/acme')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->assertGuest();
});

test('a user with a recruitment role can access the admin panel', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');

    $this->actingAs($user)->get('/admin/acme')->assertSuccessful();
});
