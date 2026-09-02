<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\RecruitmentAnalyticsService;
use App\Services\StageTransitionService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->service = app(RecruitmentAnalyticsService::class);
    $this->transitions = app(StageTransitionService::class);
    $this->start = now()->startOfMonth();
    $this->end = now()->endOfMonth();
});

test('funnel counts Sourced from application_date and later stages from stage history', function (): void {
    CandidateApplication::factory()->count(3)->create(['application_date' => now()]);
    $advanced = CandidateApplication::factory()->create(['application_date' => now(), 'current_stage' => CandidateStage::Sourced]);

    $this->transitions->transitionTo($advanced, CandidateStage::Selected);

    $funnel = $this->service->funnel($this->start, $this->end)->keyBy(fn (array $row) => $row['stage']->value);

    expect($funnel[CandidateStage::Sourced->value]['count'])->toBe(4)
        ->and($funnel[CandidateStage::Selected->value]['count'])->toBe(1)
        ->and($funnel[CandidateStage::Selected->value]['conversion_from_sourced'])->toBe(25.0);
});

test('sourceAnalytics builds a per-source funnel', function (): void {
    $source = CandidateSource::factory()->create();
    $candidate = Candidate::factory()->create(['source_id' => $source->id]);
    $application = CandidateApplication::factory()->create(['candidate_id' => $candidate->id]);

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed]);
    $this->transitions->transitionTo($application, CandidateStage::Selected);
    CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()]);

    $row = $this->service->sourceAnalytics($this->start, $this->end)->firstWhere('source.id', $source->id);

    expect($row['sourced'])->toBe(1)
        ->and($row['interviewed'])->toBe(1)
        ->and($row['selected'])->toBe(1)
        ->and($row['joined'])->toBe(1);
});

test('sourceAnalytics computes spend and cost-per-outcome from RecruitmentCost', function (): void {
    $source = CandidateSource::factory()->create();
    $candidate = Candidate::factory()->create(['source_id' => $source->id]);
    $application = CandidateApplication::factory()->create(['candidate_id' => $candidate->id]);

    RecruitmentCost::factory()->create(['source_id' => $source->id, 'amount' => 1000, 'incurred_on' => now()]);

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed]);
    $this->transitions->transitionTo($application, CandidateStage::Selected);
    CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()]);

    $row = $this->service->sourceAnalytics($this->start, $this->end)->firstWhere('source.id', $source->id);

    expect($row['spend'])->toBe(1000.0)
        ->and($row['cost_per_interview'])->toBe(1000.0)
        ->and($row['cost_per_selection'])->toBe(1000.0)
        ->and($row['cost_per_join'])->toBe(1000.0)
        ->and($row['conversion_percent'])->toBe(100.0);
});

test('sourceAnalytics returns null cost-per-outcome when there is spend but no outcomes', function (): void {
    $source = CandidateSource::factory()->create();
    RecruitmentCost::factory()->create(['source_id' => $source->id, 'amount' => 500, 'incurred_on' => now()]);

    $row = $this->service->sourceAnalytics($this->start, $this->end)->firstWhere('source.id', $source->id);

    expect($row['spend'])->toBe(500.0)
        ->and($row['cost_per_interview'])->toBeNull()
        ->and($row['cost_per_selection'])->toBeNull()
        ->and($row['cost_per_join'])->toBeNull();
});

test('sourceAnalytics respects hierarchy scoping when a user is passed', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();
    $source = CandidateSource::factory()->create();

    $visibleCandidate = Candidate::factory()->create(['source_id' => $source->id]);
    CandidateApplication::factory()->create(['candidate_id' => $visibleCandidate->id, 'recruiter_id' => $ownRecruiter->id]);

    $hiddenCandidate = Candidate::factory()->create(['source_id' => $source->id]);
    CandidateApplication::factory()->create(['candidate_id' => $hiddenCandidate->id, 'recruiter_id' => $outsider->id]);

    $user = User::factory()->create(['employee_id' => $manager->id]);

    $row = $this->service->sourceAnalytics($this->start, $this->end, $user)->firstWhere('source.id', $source->id);

    expect($row['sourced'])->toBe(1);
});

test('vacancyAgeing flags requisitions past the configured threshold as overdue', function (): void {
    RecruitmentSetting::put('vacancy_ageing_alert_days', '30', 'int');

    $overdue = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(45)]);
    $fresh = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(5)]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed, 'opening_date' => now()->subDays(90)]);

    $ageing = $this->service->vacancyAgeing()->keyBy(fn (array $row) => $row['requisition']->id);

    expect($ageing)->toHaveCount(2)
        ->and($ageing[$overdue->id]['is_overdue'])->toBeTrue()
        ->and($ageing[$fresh->id]['is_overdue'])->toBeFalse();
});

test('averageTimeToHireDays is null when there are no joins in the period', function (): void {
    expect($this->service->averageTimeToHireDays($this->start, $this->end))->toBeNull();
});

test('averageTimeToHireDays measures from application_date to actual_doj by default', function (): void {
    $application = CandidateApplication::factory()->create(['application_date' => now()->subDays(10)]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);

    expect($this->service->averageTimeToHireDays($this->start, $this->end))->toBe(10.0);
});

test('turnUpAnalysis computes the turn-up ratio from interview statuses', function (): void {
    $application = CandidateApplication::factory()->create();

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed, 'scheduled_at' => now()]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed, 'scheduled_at' => now()]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::NoShow, 'scheduled_at' => now()]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Cancelled, 'scheduled_at' => now()]);

    $result = $this->service->turnUpAnalysis($this->start, $this->end);

    expect($result['lineups'])->toBe(4)
        ->and($result['turnups'])->toBe(2)
        ->and($result['no_shows'])->toBe(1)
        ->and($result['cancelled'])->toBe(1)
        ->and($result['turnup_percent'])->toBe(50.0);
});

test('turnUpAnalysis returns a null turnup_percent when there are no line-ups', function (): void {
    $result = $this->service->turnUpAnalysis($this->start, $this->end);

    expect($result['lineups'])->toBe(0)
        ->and($result['turnups'])->toBe(0)
        ->and($result['turnup_percent'])->toBeNull();
});

test('turnUpTrend returns one row per day in the range with correct daily counts', function (): void {
    $application = CandidateApplication::factory()->create();
    // CarbonImmutable on purpose, not the mutable `now()` helper: resolvePeriod() (the only real
    // caller) always passes CarbonImmutable, and turnUpTrend()'s internal date loop previously
    // called ->addDay() without reassigning the result — a no-op on an immutable instance, so the
    // loop variable never advanced and this ran forever until OOM. A mutable Carbon would never
    // have caught that, since ->addDay() mutates it in place regardless of the (missing) reassignment.
    $day1 = CarbonImmutable::now()->startOfDay();
    $day2 = $day1->addDay();

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed, 'scheduled_at' => $day1->copy()->addHours(10)]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::NoShow, 'scheduled_at' => $day2->copy()->addHours(10)]);

    $trend = $this->service->turnUpTrend($day1, $day2->copy()->endOfDay());

    expect($trend)->toHaveCount(2);

    $trend = $trend->keyBy('date');

    expect($trend[$day1->toDateString()]['lineups'])->toBe(1)
        ->and($trend[$day1->toDateString()]['turnups'])->toBe(1)
        ->and($trend[$day2->toDateString()]['lineups'])->toBe(1)
        ->and($trend[$day2->toDateString()]['no_shows'])->toBe(1);
});

test('conversionBreakdown groups turn-up/selection/joining counts by recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'application_date' => now()]);

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed]);
    $this->transitions->transitionTo($application, CandidateStage::Selected);
    CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined]);

    $row = $this->service->conversionBreakdown('recruiter', $this->start, $this->end)->firstWhere('group', $recruiter->fullName());

    expect($row['turnups'])->toBe(1)
        ->and($row['selections'])->toBe(1)
        ->and($row['joined'])->toBe(1)
        ->and($row['selection_ratio'])->toBe(100.0)
        ->and($row['joining_ratio'])->toBe(100.0);
});

test('conversionBreakdown leaves ratios null when the denominator is zero', function (): void {
    $recruiter = Employee::factory()->create();
    CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'application_date' => now()]);

    $row = $this->service->conversionBreakdown('recruiter', $this->start, $this->end)->firstWhere('group', $recruiter->fullName());

    expect($row['turnups'])->toBe(0)
        ->and($row['selection_ratio'])->toBeNull()
        ->and($row['joining_ratio'])->toBeNull();
});

test('candidateAging buckets active applications by days since last activity', function (): void {
    CandidateApplication::factory()->create([
        'current_stage' => CandidateStage::Sourced,
        'status' => ApplicationStatus::Active,
        'last_activity_at' => now()->subDays(12),
    ]);
    CandidateApplication::factory()->create([
        'current_stage' => CandidateStage::Sourced,
        'status' => ApplicationStatus::Active,
        'last_activity_at' => now()->subDay(),
    ]);

    $row = $this->service->candidateAging()->firstWhere('stage', CandidateStage::Sourced);

    expect($row['total'])->toBe(2)
        ->and($row['buckets']['0_2'])->toBe(1)
        ->and($row['buckets']['10_plus'])->toBe(1);
});

test('positionHealth flags a requisition with no pipeline as critical', function (): void {
    $requisition = RecruitmentRequisition::factory()->create([
        'status' => RequisitionStatus::Open,
        'openings' => 5,
        'opening_date' => now(),
    ]);

    $row = $this->service->positionHealth()->firstWhere('requisition.id', $requisition->id);

    expect($row['pipeline'])->toBe(0)
        ->and($row['remaining'])->toBe(5)
        ->and($row['risk'])->toBe('critical');
});

test('positionHealth marks a requisition on_track when its pipeline comfortably covers what remains', function (): void {
    $requisition = RecruitmentRequisition::factory()->create([
        'status' => RequisitionStatus::Open,
        'openings' => 2,
        'opening_date' => now(),
    ]);

    CandidateApplication::factory()->count(4)->create([
        'requisition_id' => $requisition->id,
        'status' => ApplicationStatus::Active,
    ]);

    $row = $this->service->positionHealth()->firstWhere('requisition.id', $requisition->id);

    expect($row['risk'])->toBe('on_track');
});

test('interviewAnalytics computes completion, no-show, and selection percentages', function (): void {
    $application = CandidateApplication::factory()->create();

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed, 'result' => InterviewResult::Selected, 'scheduled_at' => now()]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed, 'result' => InterviewResult::Rejected, 'scheduled_at' => now()]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::NoShow, 'scheduled_at' => now()]);

    $result = $this->service->interviewAnalytics($this->start, $this->end);

    expect($result['scheduled'])->toBe(3)
        ->and($result['completed'])->toBe(2)
        ->and($result['completion_percent'])->toBe(66.7)
        ->and($result['no_show_percent'])->toBe(33.3)
        ->and($result['selection_percent'])->toBe(50.0);
});

test('interviewAnalytics returns null percentages for an empty period instead of dividing by zero', function (): void {
    $result = $this->service->interviewAnalytics($this->start, $this->end);

    expect($result['scheduled'])->toBe(0)
        ->and($result['completion_percent'])->toBeNull()
        ->and($result['no_show_percent'])->toBeNull()
        ->and($result['selection_percent'])->toBeNull();
});

test('offerAnalytics computes acceptance percent from decided offers only', function (): void {
    Offer::factory()->create(['status' => OfferStatus::Accepted, 'offer_date' => now()]);
    Offer::factory()->create(['status' => OfferStatus::Rejected, 'offer_date' => now()]);
    Offer::factory()->create(['status' => OfferStatus::Released, 'offer_date' => now()]);

    $result = $this->service->offerAnalytics($this->start, $this->end);

    expect($result['generated'])->toBe(3)
        ->and($result['pending'])->toBe(1)
        ->and($result['acceptance_percent'])->toBe(50.0);
});

test('offerAnalytics leaves acceptance_percent null when no offers have been decided', function (): void {
    Offer::factory()->create(['status' => OfferStatus::Released, 'offer_date' => now()]);

    $result = $this->service->offerAnalytics($this->start, $this->end);

    expect($result['acceptance_percent'])->toBeNull();
});

test('joiningAnalytics reports the near-term joining schedule and the joining percent', function (): void {
    $application = CandidateApplication::factory()->create();
    $this->transitions->transitionTo($application, CandidateStage::Selected);

    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'expected_doj' => now(),
    ]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->addDay()]);

    $result = $this->service->joiningAnalytics($this->start, $this->end);

    expect($result['joined'])->toBe(1)
        ->and($result['joining_percent'])->toBe(100.0)
        ->and($result['today'])->toBe(0)
        ->and($result['tomorrow'])->toBe(1);
});

test('joiningRisks excludes candidates who have already joined', function (): void {
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'expected_doj' => now()->subDays(5)]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->subDay()]);

    $risks = $this->service->joiningRisks();

    expect($risks)->toHaveCount(1)
        ->and($risks->first()['joining']->status)->toBe(JoiningStatus::Expected)
        ->and($risks->first()['risk'])->toBe('red');
});
