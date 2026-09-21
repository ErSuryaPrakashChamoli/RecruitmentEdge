<?php

use App\Filament\Widgets\RecruitmentOverviewStats;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

test('this month\'s trend compares the days elapsed so far with the same number of days before', function (): void {
    $this->seed(RolePermissionSeeder::class);
    travelTo('2026-09-21 12:00:00');

    CandidateApplication::factory()->count(4)->create(['application_date' => '2026-09-05']);
    CandidateApplication::factory()->count(4)->create(['application_date' => '2026-08-20']);
    // Outside the equal-length comparison window (11–31 Aug), so it must not count.
    CandidateApplication::factory()->count(6)->create(['application_date' => '2026-08-05']);

    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('chro');
    actingAs($user);

    $applications = collect(Livewire::test(RecruitmentOverviewStats::class)->instance()->getCards())
        ->firstWhere('label', 'Applications');

    expect($applications['value'])->toBe('4')
        ->and($applications['trend'])->toBe(0.0);
});
