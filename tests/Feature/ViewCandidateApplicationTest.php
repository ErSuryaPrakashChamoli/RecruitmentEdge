<?php

use App\Filament\Resources\CandidateApplications\Pages\ViewCandidateApplication;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the candidate application 360 view renders with interviews, offers, and stage history tabs', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    Interview::factory()->create(['candidate_application_id' => $application->id]);
    Offer::factory()->create(['candidate_application_id' => $application->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get("/admin/candidate-applications/{$application->id}")
        ->assertSuccessful()
        ->assertSee($application->application_code)
        ->assertSee($application->candidate->full_name)
        ->assertSee('Recruitment Timeline')
        ->assertSee('Interviews')
        ->assertSee('Offers')
        ->assertSee('Stage History')
        ->assertSee('Activities');
});

test('a recruiter can schedule an interview from the candidate 360 header', function (): void {
    $recruiter = Employee::factory()->create();
    $interviewer = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(ViewCandidateApplication::class, ['record' => $application->getRouteKey()])
        ->callAction(TestAction::make('scheduleInterview'), data: [
            'interviewer_id' => $interviewer->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'video_call',
        ]);

    expect(Interview::query()->where('candidate_application_id', $application->id)->count())->toBe(1);
});

test('a recruiter can add a follow-up from the candidate 360 header', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(ViewCandidateApplication::class, ['record' => $application->getRouteKey()])
        ->callAction(TestAction::make('addFollowup'), data: [
            'followup_type' => 'call',
            'followup_date' => now()->addDay(),
        ]);

    expect(RecruitmentFollowup::query()->where('candidate_application_id', $application->id)->count())->toBe(1);
});

test('updating the next action sets next_followup_at on the application', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $newDate = now()->addDays(2);

    Livewire::test(ViewCandidateApplication::class, ['record' => $application->getRouteKey()])
        ->callAction(TestAction::make('updateNextFollowup'), data: [
            'next_followup_at' => $newDate,
        ]);

    expect($application->fresh()->next_followup_at->toDateString())->toBe($newDate->toDateString());
});

test('a recruiter outside the hierarchy cannot view an application that is not theirs', function (): void {
    $owner = Employee::factory()->create();
    $outsider = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $owner->id]);

    $user = User::factory()->create(['employee_id' => $outsider->id]);
    $user->assignRole('recruiter');

    actingAs($user)->get("/admin/candidate-applications/{$application->id}")->assertNotFound();
});
