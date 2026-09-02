<?php

use App\Filament\Widgets\FollowUpCalendar;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\freezeTime;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('selecting a date shows only the interviews and joinings scheduled on that date', function (): void {
    freezeTime();

    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();

    $selectedDayApplication = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $selectedDayApplication->candidate->update(['full_name' => 'Selected Day Candidate']);
    Interview::factory()->create([
        'candidate_application_id' => $selectedDayApplication->id,
        'scheduled_at' => now()->addDays(3)->setTime(10, 0),
    ]);

    $joiningApplication = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $joiningApplication->candidate->update(['full_name' => 'Joining Day Candidate']);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $joiningApplication->id,
        'expected_doj' => now()->addDays(3),
    ]);

    $otherDayApplication = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $otherDayApplication->candidate->update(['full_name' => 'Other Day Candidate']);
    Interview::factory()->create([
        'candidate_application_id' => $otherDayApplication->id,
        'scheduled_at' => now()->addDays(5)->setTime(10, 0),
    ]);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    actingAs($user);

    Livewire::test(FollowUpCalendar::class)
        ->call('selectDate', now()->addDays(3)->toDateString())
        ->assertSee('Selected Day Candidate')
        ->assertSee('Joining Day Candidate')
        ->assertDontSee('Other Day Candidate');
});

test('a manager cannot see interviews scheduled for a recruiter outside their hierarchy', function (): void {
    freezeTime();

    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visibleApplication = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $visibleApplication->candidate->update(['full_name' => 'Visible Team Candidate']);
    Interview::factory()->create([
        'candidate_application_id' => $visibleApplication->id,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);

    $hiddenApplication = CandidateApplication::factory()->create(['recruiter_id' => $outsider->id]);
    $hiddenApplication->candidate->update(['full_name' => 'Hidden Team Candidate']);
    Interview::factory()->create([
        'candidate_application_id' => $hiddenApplication->id,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    actingAs($user);

    Livewire::test(FollowUpCalendar::class)
        ->call('selectDate', now()->addDay()->toDateString())
        ->assertSee('Visible Team Candidate')
        ->assertDontSee('Hidden Team Candidate');
});

test('navigating to the next month advances the displayed month by one', function (): void {
    freezeTime();

    $user = User::factory()->create();
    $user->assignRole('recruiter');

    actingAs($user);

    Livewire::test(FollowUpCalendar::class)
        ->call('nextMonth')
        ->assertSet('month', now()->addMonthNoOverflow()->startOfMonth()->toDateString());
});
