<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\MetricResultStatus;
use App\Enums\RequisitionStatus;
use App\Enums\StageHistoryEvent;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringOutcomeSnapshot;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
use App\Services\RequisitionApprovalService;

/**
 * Phase 8.5: the governed time metrics — time to hire (D1, D1a, D2), time to fill (D39), time in
 * stage (D3) and requisition ageing.
 */
function timeMetric(string $key, array $filters = [], ?MetricPeriod $period = null): MetricResult
{
    return app(MetricService::class)->get($key, MetricQuery::make($period ?? MetricPeriod::lastDays(30), null, $filters));
}

function timeMetricsHire(int $appliedDaysAgo, ?array $frozenStart = null, array $applicationAttributes = []): CandidateJoining
{
    $application = CandidateApplication::factory()->create(['application_date' => now()->subDays($appliedDaysAgo), ...$applicationAttributes]);
    $joining = lifecycleFixture(fn () => CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->toDateString()]));

    if ($frozenStart !== null) {
        HiringOutcomeSnapshot::factory()->create([
            'candidate_application_id' => $application->id,
            'candidate_joining_id' => $joining->id,
            'joined_on' => now()->toDateString(),
            'facts' => ['time_to_hire' => $frozenStart],
        ]);
    }

    return $joining;
}

test('time to hire is the median from each hire\'s frozen start point, unaffected by a later setting change (D1)', function (): void {
    foreach ([10, 20, 30] as $days) {
        timeMetricsHire($days, ['start_point' => 'candidate_applied', 'start_date' => now()->subDays($days)->toDateString()]);
    }

    RecruitmentSetting::put('time_to_hire_start_point', 'candidate_sourced', 'string');
    $result = timeMetric('hiring.time_to_hire');

    expect($result->value)->toBe(20.0)
        ->and($result->detail('start_point_live_for'))->toBe(0);
});

test('a hire captured without a snapshot uses the current start point and is reported as such', function (): void {
    foreach ([5, 6, 7] as $days) {
        timeMetricsHire($days);
    }

    $result = timeMetric('hiring.time_to_hire');

    expect($result->value)->toBe(6.0)
        ->and($result->detail('start_point_live_for'))->toBe(3);
});

test('time to hire counts a hire with no start date as unknown and withholds a median below the minimum sample', function (): void {
    timeMetricsHire(10, ['start_point' => 'candidate_applied', 'start_date' => now()->subDays(10)->toDateString()]);
    $unknown = timeMetricsHire(10);
    HiringOutcomeSnapshot::factory()->create(['candidate_application_id' => $unknown->candidate_application_id, 'candidate_joining_id' => $unknown->id, 'facts' => ['time_to_hire' => null]]);

    $result = timeMetric('hiring.time_to_hire');

    expect($result->status)->toBe(MetricResultStatus::InsufficientSample)
        ->and($result->value)->toBeNull()
        ->and($result->sampleSize)->toBe(1)
        ->and($result->unknownCount)->toBe(1)
        ->and($result->detail('mean'))->toBeNull();
});

test('a Joined record without an actual joining date is never measured to a planned date (D2)', function (): void {
    lifecycleFixture(fn () => CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => null, 'expected_doj' => now()]));

    expect(timeMetric('hiring.time_to_hire')->status)->toBe(MetricResultStatus::NoData)
        ->and(timeMetric('hiring.hires')->value)->toBe(0.0);
});

test('time to fill is the median from opening to the first hire of each requisition (D39)', function (): void {
    foreach ([10, 20, 30] as $days) {
        $requisition = RecruitmentRequisition::factory()->create(['opening_date' => now()->subDays($days)]);
        timeMetricsHire(5, applicationAttributes: ['requisition_id' => $requisition->id]);
    }

    // A second, later hire on the last requisition does not change its first-hire date.
    timeMetricsHire(2, applicationAttributes: ['requisition_id' => $requisition->id]);

    $result = timeMetric('hiring.time_to_fill');

    expect($result->value)->toBe(20.0)
        ->and($result->detail('requisitions'))->toBe(3);
});

test('time in stage adds up every visit and a status change never splits a stage (D3, DF-9)', function (): void {
    config(['metrics.min_sample' => 1]);
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected, 'created_at' => now()->subDays(20)]);
    $row = fn (?CandidateStage $from, CandidateStage $to, int $daysAgo, StageHistoryEvent $event = StageHistoryEvent::StageEntered) => $application->stageHistory()->forceCreate([
        'previous_stage' => $from, 'new_stage' => $to, 'event' => $event, 'created_at' => now()->subDays($daysAgo),
    ]);

    $row(CandidateStage::Sourced, CandidateStage::Screened, 16);
    $row(CandidateStage::Screened, CandidateStage::Screened, 12, StageHistoryEvent::Held);
    $row(CandidateStage::Screened, CandidateStage::Screened, 10, StageHistoryEvent::Reactivated);
    $row(CandidateStage::Screened, CandidateStage::Selected, 6);

    $stages = collect(timeMetric('pipeline.time_in_stage')->detail('by_stage'))->keyBy('stage');

    // Sourced: created 20 days ago -> Screened 16 days ago = 4 days; Screened: 16 -> 6 = 10 days,
    // one visit — the hold and reactivation rows are not stage entries.
    expect($stages['sourced']['median_days'])->toBe(4.0)
        ->and($stages['screened']['median_days'])->toBe(10.0)
        ->and($stages['screened']['sample_size'])->toBe(1);
});

test('a stage visited twice counts both visits', function (): void {
    config(['metrics.min_sample' => 1]);
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected, 'created_at' => now()->subDays(30)]);

    foreach ([[CandidateStage::Sourced, CandidateStage::Interview1, 28], [CandidateStage::Interview1, CandidateStage::Interview2, 25], [CandidateStage::Interview2, CandidateStage::Interview1, 20], [CandidateStage::Interview1, CandidateStage::Selected, 18]] as [$from, $to, $daysAgo]) {
        $application->stageHistory()->forceCreate(['previous_stage' => $from, 'new_stage' => $to, 'event' => StageHistoryEvent::StageEntered, 'created_at' => now()->subDays($daysAgo)]);
    }

    $interview = collect(timeMetric('pipeline.time_in_stage')->detail('by_stage'))->firstWhere('stage', 'interview_1');

    // Interview 1 was visited 28 -> 25 (3 days) and 20 -> 18 (2 days).
    expect($interview['median_days'])->toBe(5.0);
});

test('time in stage medians per stage once the sample is large enough', function (): void {
    foreach ([2, 4, 6] as $days) {
        $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened, 'created_at' => now()->subDays(10 + $days)]);
        $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Screened, 'event' => StageHistoryEvent::StageEntered, 'created_at' => now()->subDays(10)]);
    }

    $sourced = collect(timeMetric('pipeline.time_in_stage')->detail('by_stage'))->firstWhere('stage', 'sourced');

    expect($sourced['median_days'])->toBe(4.0)
        ->and($sourced['sample_size'])->toBe(3);
});

test('requisition ageing is never negative and a closed requisition stops ageing', function (): void {
    $future = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->addDays(10)]);
    $closed = lifecycleFixture(fn () => RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed, 'opening_date' => now()->subDays(60), 'closed_at' => now()->subDays(20)]));

    expect($future->ageingInDays())->toBe(0)
        ->and($future->isNotYetOpen())->toBeTrue()
        ->and($closed->ageingInDays())->toBe(40);
});

test('requisition ageing reports the median age and the overdue count of open requisitions', function (): void {
    RecruitmentSetting::put('vacancy_ageing_alert_days', '30', 'int');

    foreach ([10, 35, 50] as $days) {
        RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays($days)]);
    }
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed, 'opening_date' => now()->subDays(90)]);

    $result = app(MetricService::class)->get('requisition.ageing', MetricQuery::make(null, null));

    expect($result->value)->toBe(35.0)
        ->and($result->detail('overdue'))->toBe(2)
        ->and($result->detail('open'))->toBe(3);
});

test('closing a requisition records when it closed', function (): void {
    $requisition = lifecycleFixture(fn () => RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]));

    app(RequisitionApprovalService::class)->close($requisition, null, 'Filled');

    expect($requisition->fresh()->closed_at)->not->toBeNull();
});
