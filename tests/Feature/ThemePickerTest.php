<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the theme picker renders on the profile page, not the header or topbar', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/profile')
        ->assertSuccessful()
        ->assertSee('Appearance')
        ->assertSee('Executive Navy')
        ->assertSee('Modern Indigo')
        ->assertSee('Teal & Slate')
        ->assertSee('Graphite & Electric Blue')
        ->assertSee('Sunset Coral')
        ->assertSee('Emerald Green')
        ->assertSee('Purple Royale')
        ->assertSee('Minimal Beige');

    actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertDontSee('Executive Navy')
        ->assertSee('data-app-theme', false);
});
