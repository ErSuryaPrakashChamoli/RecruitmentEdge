<?php

use App\Enums\InterviewStatus;
use App\Filament\Pages\InterviewWorkspace;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the interview workspace renders with real interviews and a summary', function (): void {
    $recruiter = Employee::factory()->create();
    Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'scheduled_at' => now(),
        'status' => InterviewStatus::Scheduled,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/interview-workspace')
        ->assertSuccessful()
        ->assertSee('Interviews Today')
        ->assertSee('Feedback Pending');
});

test('the today summary counts real scheduled interviews', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'scheduled_at' => now(), 'status' => InterviewStatus::Confirmed]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'scheduled_at' => now(), 'status' => InterviewStatus::Scheduled]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $summary = Livewire::test(InterviewWorkspace::class)->instance()->getTodaySummary();

    expect($summary['today'])->toBe(2)
        ->and($summary['confirmed'])->toBe(1)
        ->and($summary['pending_confirmation'])->toBe(1);
});

test('confirming an interview from the workspace calls the real confirm mutation', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(InterviewWorkspace::class)
        ->callAction(TestAction::make('confirm')->arguments(['record' => $interview->id]));

    expect($interview->fresh()->status)->toBe(InterviewStatus::Confirmed);
});

test('rescheduling an interview from the workspace updates scheduled_at and notifies the recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled]);

    $chro = User::factory()->create();
    $chro->assignRole('chro');
    actingAs($chro);

    $newTime = now()->addDays(3);

    Livewire::test(InterviewWorkspace::class)
        ->callAction(TestAction::make('reschedule')->arguments(['record' => $interview->id]), data: ['scheduled_at' => $newTime]);

    expect($interview->fresh()->scheduled_at->toDateTimeString())->toBe($newTime->toDateTimeString())
        ->and($recruiterUser->notifications()->count())->toBe(1);
});

test('adding feedback from the workspace creates a real InterviewFeedback row', function (): void {
    $recruiter = Employee::factory()->create();
    $interviewer = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $interview = Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => InterviewStatus::Completed,
        'result' => null,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(InterviewWorkspace::class)
        ->callAction(TestAction::make('addFeedback')->arguments(['interviewId' => $interview->id]), data: [
            'interviewer_id' => $interviewer->id,
            'recommendation' => 'recommend',
            'feedback' => 'Strong candidate.',
        ]);

    expect(InterviewFeedback::query()->where('interview_id', $interview->id)->count())->toBe(1);
});

test('a recruiter outside the hierarchy does not see another team\'s interview in the workspace', function (): void {
    $owner = Employee::factory()->create();
    $outsider = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $owner->id]);
    $interview = Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'scheduled_at' => now(),
    ]);
    $interview->candidateApplication->candidate->update(['full_name' => 'Hidden Interview Candidate']);

    $user = User::factory()->create(['employee_id' => $outsider->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(InterviewWorkspace::class)->assertDontSee('Hidden Interview Candidate');
});
