<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Filament\Pages\InterviewWorkspace;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('chro');
    actingAs($this->user);
});

test('pending confirmation counts every upcoming scheduled or rescheduled interview, not just today', function (): void {
    Interview::factory()->create(['scheduled_at' => now()->addDays(3), 'status' => InterviewStatus::Rescheduled]);
    Interview::factory()->create(['scheduled_at' => now()->addDays(5), 'status' => InterviewStatus::Scheduled]);
    Interview::factory()->create(['scheduled_at' => now()->addDay(), 'status' => InterviewStatus::Confirmed]);
    Interview::factory()->create(['scheduled_at' => now()->subDays(2), 'status' => InterviewStatus::Scheduled]);

    $summary = Livewire::test(InterviewWorkspace::class)->instance()->getTodaySummary();

    expect($summary['pending_confirmation'])->toBe(2);
});

test('the unconfirmed tab lists upcoming unconfirmed interviews with a one-click confirm', function (): void {
    $interview = Interview::factory()->create(['scheduled_at' => now()->addDays(4), 'status' => InterviewStatus::Rescheduled]);
    $interview->candidateApplication->candidate->update(['full_name' => 'Awaiting Confirmation Person']);
    $confirmed = Interview::factory()->create(['scheduled_at' => now()->addDays(4), 'status' => InterviewStatus::Confirmed]);
    $confirmed->candidateApplication->candidate->update(['full_name' => 'Already Confirmed Person']);

    Livewire::test(InterviewWorkspace::class)
        ->call('setView', 'unconfirmed')
        ->assertSee('Awaiting Confirmation Person')
        ->assertDontSee('Already Confirmed Person')
        ->assertSeeHtml("mountAction('confirm', { record: {$interview->id} })")
        ->callAction(TestAction::make('confirm')->arguments(['record' => $interview->id]));

    expect($interview->fresh()->status)->toBe(InterviewStatus::Confirmed);
});

test('interview cards render complete, no show and hold buttons for open interviews', function (): void {
    $interview = Interview::factory()->create(['scheduled_at' => now(), 'status' => InterviewStatus::Confirmed]);

    Livewire::test(InterviewWorkspace::class)
        ->assertSeeHtml("mountAction('complete', { record: {$interview->id} })")
        ->assertSeeHtml("mountAction('noShow', { record: {$interview->id} })")
        ->assertSeeHtml("mountAction('hold', { record: {$interview->id} })");
});

test('an interview can be put on hold from the workspace', function (): void {
    $interview = Interview::factory()->create(['scheduled_at' => now(), 'status' => InterviewStatus::Scheduled]);

    Livewire::test(InterviewWorkspace::class)
        ->callAction(TestAction::make('hold')->arguments(['record' => $interview->id]), data: ['remarks' => 'Candidate travelling']);

    expect($interview->fresh()->status)->toBe(InterviewStatus::Hold);
});

test('the day view shows the selected date and navigates between days', function (): void {
    $interview = Interview::factory()->create(['scheduled_at' => now()->addDays(2)->setTime(11, 0)]);
    $interview->candidateApplication->candidate->update(['full_name' => 'Day After Tomorrow Person']);

    Livewire::test(InterviewWorkspace::class)
        ->call('setView', 'day')
        ->assertDontSee('Day After Tomorrow Person')
        ->call('nextDay')
        ->call('nextDay')
        ->assertSet('selectedDate', now()->addDays(2)->toDateString())
        ->assertSee('Day After Tomorrow Person');
});

test('the week view navigates to the next week', function (): void {
    $interview = Interview::factory()->create(['scheduled_at' => now()->startOfWeek()->addWeek()->addDay()->setTime(10, 0)]);
    $interview->candidateApplication->candidate->update(['full_name' => 'Next Week Person']);

    $component = Livewire::test(InterviewWorkspace::class)
        ->call('setView', 'week')
        ->assertDontSee('Next Week Person')
        ->call('nextWeek')
        ->assertSee('Next Week Person');

    expect($component->instance()->getInterviewsForActiveView())->toHaveCount(1);
});

test('month cells carry per-status interview counts', function (): void {
    $day = now()->startOfMonth()->addDays(9)->setTime(10, 0);
    Interview::factory()->count(2)->create(['scheduled_at' => $day, 'status' => InterviewStatus::Scheduled]);
    Interview::factory()->create(['scheduled_at' => $day, 'status' => InterviewStatus::Completed]);

    $counts = Livewire::test(InterviewWorkspace::class)->instance()->getInterviewStatusCountsInMonth();

    expect($counts->get($day->toDateString())->all())->toBe(['scheduled' => 2, 'completed' => 1]);
});

test('the calendar view renders colour-coded status badges', function (): void {
    Interview::factory()->create(['scheduled_at' => now()->setTime(10, 0), 'status' => InterviewStatus::NoShow]);

    Livewire::test(InterviewWorkspace::class)
        ->call('setView', 'calendar')
        ->assertSeeHtml('title="1 No Show"')
        ->assertSeeHtml('bg-rose-500 text-white')
        ->call('openDay', now()->toDateString())
        ->assertSet('activeView', 'day');
});

test('the interviewer load panel flags an interviewer booked above the configured daily capacity', function (): void {
    RecruitmentSetting::put('interviewer_daily_capacity', 2, 'integer', 'interviews');

    $busy = Employee::factory()->create(['first_name' => 'Busy']);
    $light = Employee::factory()->create(['first_name' => 'Light']);
    Interview::factory()->count(3)->create(['interviewer_id' => $busy->id, 'scheduled_at' => now()->setTime(10, 0)]);
    Interview::factory()->create(['interviewer_id' => $busy->id, 'scheduled_at' => now()->setTime(15, 0), 'status' => InterviewStatus::Cancelled]);
    Interview::factory()->create(['interviewer_id' => $light->id, 'scheduled_at' => now()->setTime(12, 0)]);

    $load = Livewire::test(InterviewWorkspace::class)
        ->assertSee('Over-booked')
        ->instance()
        ->getInterviewerLoad();

    expect($load['capacity'])->toBe(2)
        ->and($load['rows']->firstWhere('name', $busy->fullName()))->toMatchArray(['total' => 3, 'overbooked' => true])
        ->and($load['rows']->firstWhere('name', $light->fullName()))->toMatchArray(['total' => 1, 'overbooked' => false]);
});

test('the interviewer daily capacity defaults to 4', function (): void {
    expect(Livewire::test(InterviewWorkspace::class)->instance()->interviewerDailyCapacity())->toBe(4);
});

test('an interview can be scheduled from the workspace for an application in the viewer\'s hierarchy', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);

    Livewire::test(InterviewWorkspace::class)
        ->callAction('scheduleInterview', data: [
            'candidate_application_id' => $application->id,
            'interviewer_id' => Interviewer::factory()->create()->employee_id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
        ])
        ->assertHasNoFormErrors();

    expect($application->interviews()->count())->toBe(1)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::InterviewScheduled);
});

test('the workspace schedule action does not offer applications outside the viewer\'s hierarchy', function (): void {
    $recruiter = Employee::factory()->create();
    $outsider = Employee::factory()->create();
    $own = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $foreign = CandidateApplication::factory()->create(['recruiter_id' => $outsider->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(InterviewWorkspace::class)
        ->callAction('scheduleInterview', data: [
            'candidate_application_id' => $foreign->id,
            'interviewer_id' => $recruiter->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'phone',
        ])
        ->assertHasFormErrors(['candidate_application_id']);

    expect(Interview::query()->count())->toBe(0)
        ->and($own->exists)->toBeTrue();
});
