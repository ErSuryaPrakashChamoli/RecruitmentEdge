<?php

use App\Enums\AiRiskLevel;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Events\InterviewScheduled;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\User;
use App\Services\AI\Tools\ActionTools\ScheduleInterviewTool;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    Event::fake([InterviewScheduled::class]);

    $this->user = User::factory()->create();
    $this->user->assignRole('chro');
});

test('the tool stays behind the confirmation gate', function (): void {
    expect(app(ScheduleInterviewTool::class)->riskLevel())->toBe(AiRiskLevel::External);
});

test('the tool schedules through the interview service, advancing the stage and raising the calendar-sync event', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);
    $interviewer = Employee::factory()->create();

    $result = app(ScheduleInterviewTool::class)->handle([
        'application_id' => $application->id,
        'interviewer_employee_id' => $interviewer->id,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'mode' => 'video_call',
        'meeting_link' => 'https://meet.example.com/ai',
    ], $this->user);

    $interview = Interview::query()->sole();

    expect($result->success)->toBeTrue()
        ->and($interview->round_number)->toBe(1)
        ->and($interview->meeting_link)->toBe('https://meet.example.com/ai')
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::InterviewScheduled);
    Event::assertDispatched(InterviewScheduled::class, fn (InterviewScheduled $e) => $e->interview->is($interview));
});

test('the tool fails cleanly for an inactive application without syncing the calendar', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected]);

    $result = app(ScheduleInterviewTool::class)->handle([
        'application_id' => $application->id,
        'interviewer_employee_id' => Employee::factory()->create()->id,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'mode' => 'phone',
    ], $this->user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('not active')
        ->and(Interview::query()->count())->toBe(0);
    Event::assertNotDispatched(InterviewScheduled::class);
});
