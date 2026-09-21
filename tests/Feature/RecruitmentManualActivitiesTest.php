<?php

use App\Enums\TargetMetric;
use App\Filament\Resources\RecruitmentManualActivities\Pages\ListRecruitmentManualActivities;
use App\Models\Employee;
use App\Models\RecruitmentManualActivity;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('the manual activities list shows each entry with its metric label', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $recruiter = Employee::factory()->create();
    $activity = RecruitmentManualActivity::factory()->create([
        'recruiter_id' => $recruiter->id,
        'metric' => TargetMetric::ProfilesSourced->value,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    expect($activity->fresh()->metric)->toBe(TargetMetric::ProfilesSourced);

    Livewire::test(ListRecruitmentManualActivities::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$activity])
        ->assertSee(TargetMetric::ProfilesSourced->label());
});
