<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a user with ai.query can open the AI Copilot page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');

    $this->actingAs($user)->get('/admin/ai-copilot')->assertSuccessful();
});

test('a user without any role cannot open the AI Copilot page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/ai-copilot')->assertForbidden();
});
