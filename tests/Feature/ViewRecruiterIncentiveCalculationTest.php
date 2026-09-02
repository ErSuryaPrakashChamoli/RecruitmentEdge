<?php

use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the incentive statement view renders the slab breakdown when a slab is set', function (): void {
    $recruiter = Employee::factory()->create();
    $slab = RecruitmentIncentiveSlab::factory()->create(['achievement_min' => 80, 'achievement_max' => 100, 'amount' => 2500]);
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $recruiter->id,
        'incentive_slab_id' => $slab->id,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get("/admin/recruiter-incentive-calculations/{$calculation->id}")
        ->assertSuccessful()
        ->assertSee('Slab Band')
        ->assertSee('80.0% – 100.0%');
});

test('the incentive statement view renders gracefully when no slab is set', function (): void {
    $recruiter = Employee::factory()->create();
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $recruiter->id,
        'incentive_slab_id' => null,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get("/admin/recruiter-incentive-calculations/{$calculation->id}")
        ->assertSuccessful();
});
