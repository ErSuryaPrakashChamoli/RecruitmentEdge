<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\TargetMetric;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentDailyTarget;
use App\Services\RecruiterDailyMetricsService;
use App\Services\StageTransitionService;

beforeEach(function (): void {
    $this->service = app(RecruiterDailyMetricsService::class);
});

test('counts calls and connected calls from the authoritative activity log', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyActivity::factory()->count(3)->create([
        'recruiter_id' => $recruiter->id,
        'activity_type' => ActivityType::Call,
        'outcome' => ActivityOutcome::NoAnswer,
        'activity_datetime' => now(),
    ]);

    RecruitmentDailyActivity::factory()->count(2)->create([
        'recruiter_id' => $recruiter->id,
        'activity_type' => ActivityType::Call,
        'outcome' => ActivityOutcome::Connected,
        'activity_datetime' => now(),
    ]);

    expect($this->service->actualFor($recruiter, TargetMetric::Calls, now(), now()))->toBe(5)
        ->and($this->service->actualFor($recruiter, TargetMetric::ConnectedCalls, now(), now()))->toBe(2);
});

test('counts profiles sourced from candidates created by the recruiter', function (): void {
    $recruiter = Employee::factory()->create();

    Candidate::factory()->count(4)->create(['created_by' => $recruiter->id]);
    Candidate::factory()->create(['created_by' => Employee::factory()->create()->id]);

    expect($this->service->actualFor($recruiter, TargetMetric::ProfilesSourced, now(), now()))->toBe(4);
});

test('counts stage-reached metrics from stage history written by the recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'current_stage' => CandidateStage::Sourced,
    ]);

    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Interested, $recruiter);
    $application->refresh();
    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Screened, $recruiter);

    expect($this->service->actualFor($recruiter, TargetMetric::Screening, now(), now()))->toBe(1)
        ->and($this->service->actualFor($recruiter, TargetMetric::InterestedCandidates, now(), now()))->toBe(1)
        ->and($this->service->actualFor($recruiter, TargetMetric::Selections, now(), now()))->toBe(0);
});

test('counts interviews completed, offers made, and candidates joined for the recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => InterviewStatus::Completed,
        'scheduled_at' => now(),
    ]);
    Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => InterviewStatus::Scheduled,
        'scheduled_at' => now(),
    ]);

    Offer::factory()->create(['candidate_application_id' => $application->id, 'offer_date' => now()]);

    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);

    expect($this->service->actualFor($recruiter, TargetMetric::Interviews, now(), now()))->toBe(1)
        ->and($this->service->actualFor($recruiter, TargetMetric::Offers, now(), now()))->toBe(1)
        ->and($this->service->actualFor($recruiter, TargetMetric::Joining, now(), now()))->toBe(1);
});

test('accountabilityFor combines target, actual, achievement, and gap per metric', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'target_value' => 10,
        'effective_from' => now()->startOfMonth(),
    ]);

    RecruitmentDailyActivity::factory()->count(12)->create([
        'recruiter_id' => $recruiter->id,
        'activity_type' => ActivityType::Call,
        'activity_datetime' => now(),
    ]);

    $row = $this->service->accountabilityFor($recruiter, now())
        ->firstWhere('metric', TargetMetric::Calls);

    expect($row['target'])->toBe(10)
        ->and($row['actual'])->toBe(12)
        ->and($row['achievement'])->toBe(120.0)
        ->and($row['gap'])->toBe(2);
});
