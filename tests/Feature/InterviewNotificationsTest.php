<?php

use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\Pages\CreateInterview;
use App\Filament\Resources\Interviews\Pages\ListInterviews;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->assignRole('chro');
    actingAs($this->actor);
});

test('scheduling an interview notifies the interviewer', function (): void {
    $interviewer = Employee::factory()->create();
    $interviewerUser = User::factory()->create(['employee_id' => $interviewer->id]);
    $application = CandidateApplication::factory()->create();

    Livewire::test(CreateInterview::class)
        ->fillForm([
            'candidate_application_id' => $application->id,
            'round_number' => 1,
            'interviewer_id' => $interviewer->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'video_call',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($interviewerUser->notifications()->count())->toBe(1)
        ->and($interviewerUser->notifications()->first()->data['title'])->toBe('[Interviews] Interview scheduled');
});

test('rescheduling an interview notifies the recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $interview = Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => InterviewStatus::Scheduled,
    ]);

    Livewire::test(ListInterviews::class)
        ->callAction(
            TestAction::make('reschedule')->table($interview),
            data: ['scheduled_at' => now()->addDays(3)],
        );

    expect($recruiterUser->notifications()->count())->toBe(1)
        ->and($recruiterUser->notifications()->first()->data['title'])->toBe('[Interviews] Interview rescheduled');
});

test('marking a no-show notifies the recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $interview = Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => InterviewStatus::Scheduled,
    ]);

    Livewire::test(ListInterviews::class)
        ->callAction(TestAction::make('noShow')->table($interview));

    expect($recruiterUser->notifications()->count())->toBe(1)
        ->and($recruiterUser->notifications()->first()->data['title'])->toBe('[Interviews] Candidate no-show');
});
