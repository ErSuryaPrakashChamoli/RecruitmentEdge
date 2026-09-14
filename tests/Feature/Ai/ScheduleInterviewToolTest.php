<?php

use App\Enums\AiRiskLevel;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\User;
use App\Services\AI\Calendar\Contracts\CalendarProviderInterface;
use App\Services\AI\Tools\ActionTools\ScheduleInterviewTool;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->calendar = Mockery::mock(CalendarProviderInterface::class);
    app()->instance(CalendarProviderInterface::class, $this->calendar);

    $this->user = User::factory()->create();
    $this->user->assignRole('chro');
});

test('the tool stays behind the confirmation gate', function (): void {
    expect(app(ScheduleInterviewTool::class)->riskLevel())->toBe(AiRiskLevel::External);
});

test('the tool schedules through the interview service, advancing the stage and syncing the calendar', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);
    $interviewer = Employee::factory()->create();

    $this->calendar->shouldReceive('createEvent')->once()->andReturn(null);

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
});

test('the tool fails cleanly for an inactive application without syncing the calendar', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected]);

    $this->calendar->shouldNotReceive('createEvent');

    $result = app(ScheduleInterviewTool::class)->handle([
        'application_id' => $application->id,
        'interviewer_employee_id' => Employee::factory()->create()->id,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'mode' => 'phone',
    ], $this->user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('not active')
        ->and(Interview::query()->count())->toBe(0);
});
