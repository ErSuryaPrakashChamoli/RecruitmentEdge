<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a user with ai.query can open the AI Copilot page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');

    $this->actingAs($user)->get('/admin/acme/ai-copilot')->assertSuccessful();
});

test('a user without any role cannot open the AI Copilot page', function (): void {
    $user = User::factory()->create();

    // SaaS-2: an identity that can enter no tenant is signed out (no 403 page inside a session).
    $this->actingAs($user)->get('/admin/acme/ai-copilot')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->assertGuest();
});
