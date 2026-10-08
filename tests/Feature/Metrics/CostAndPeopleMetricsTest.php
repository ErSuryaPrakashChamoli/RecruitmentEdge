<?php

use App\Enums\ActivityType;
use App\Enums\CandidateStage;
use App\Enums\HealthStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeType;
use App\Enums\RequisitionStatus;
use App\Enums\StageHistoryEvent;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringHealthSnapshot;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
use App\Services\Outcomes\OutcomeAnalyticsService;
use App\Services\Outcomes\OutcomeCalculator;
use App\Services\Outcomes\OutcomeService;
use Database\Seeders\RolePermissionSeeder;

/**
 * Phase 8.5: cost per hire (CPH decision), recruiter activity and outcomes (D8, D20/D40), Hiring
 * Health, and the Outcome Loop metrics (withholding in the service, D4, D6).
 */
function peopleMetric(string $key, ?User $viewer = null, array $filters = []): MetricResult
{
    return app(MetricService::class)->get($key, MetricQuery::make(MetricPeriod::lastDays(30), $viewer, $filters));
}

function peopleHire(array $applicationAttributes = []): CandidateJoining
{
    return lifecycleFixture(fn () => CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create($applicationAttributes)->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now()->toDateString(),
    ]));
}

test('cost per hire uses one scope on both sides: the requisitions the team is involved in', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $manager = Employee::factory()->create();
    $team = RecruitmentRequisition::factory()->create(['hiring_manager_id' => $manager->id]);
    $other = RecruitmentRequisition::factory()->create();

    RecruitmentCost::factory()->create(['amount' => 12000, 'incurred_on' => now(), 'requisition_id' => $team->id]);
    RecruitmentCost::factory()->create(['amount' => 99000, 'incurred_on' => now(), 'requisition_id' => $other->id]);
    RecruitmentCost::factory()->create(['amount' => 50000, 'incurred_on' => now(), 'requisition_id' => null]);
    // The hire is owned by a recruiter outside the manager's team, on the manager's requisition.
    peopleHire(['requisition_id' => $team->id]);
    peopleHire(['requisition_id' => $team->id]);
    peopleHire(['requisition_id' => $other->id]);

    $viewer = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
    $chro = User::factory()->create()->assignRole('chro');

    $scoped = peopleMetric('cost.cost_per_hire', $viewer);
    $all = peopleMetric('cost.cost_per_hire', $chro);

    expect($scoped->value)->toBe(6000.0)
        ->and($scoped->detail('total_cost'))->toBe(12000.0)
        ->and($all->value)->toBe(round(161000 / 3, 2));
});

test('cost per hire with no hires is no value, never zero or infinite', function (): void {
    RecruitmentCost::factory()->create(['amount' => 5000, 'incurred_on' => now()]);

    $result = peopleMetric('cost.cost_per_hire');

    expect($result->value)->toBeNull()
        ->and($result->detail('total_cost'))->toBe(5000.0);
});

test('recruiter activity credits the person who did it and never counts a status change (D8, DF-9)', function (): void {
    $recruiter = Employee::factory()->create();
    RecruitmentDailyActivity::factory()->count(2)->create(['recruiter_id' => $recruiter->id, 'activity_type' => ActivityType::Call, 'activity_datetime' => now()->subHour()]);
    Candidate::factory()->create(['created_by' => $recruiter->id]);

    $application = CandidateApplication::factory()->create();
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Shortlisted, 'event' => StageHistoryEvent::StageEntered, 'changed_by' => $recruiter->id]);
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Shortlisted, 'new_stage' => CandidateStage::Shortlisted, 'event' => StageHistoryEvent::Rejected, 'changed_by' => $recruiter->id]);

    $byMetric = peopleMetric('recruiter.activity', filters: ['recruiter_id' => $recruiter->id])->detail('by_metric');

    expect($byMetric['calls'])->toBe(2)
        ->and($byMetric['profiles_sourced'])->toBe(1)
        ->and($byMetric['shortlisted'])->toBe(1);
});

test('recruiter outcomes are the canonical outcome metrics for exactly one recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    peopleHire(['recruiter_id' => $recruiter->id]);
    peopleHire();

    $result = peopleMetric('recruiter.outcomes', filters: ['recruiter_id' => $recruiter->id]);

    expect($result->value)->toBe(1.0)
        ->and(array_keys($result->detail('components')))->toBe(['hiring.hires', 'offer.acceptance_rate', 'joining.join_rate', 'hiring.time_to_hire']);
});

test('recruiter outcomes need a recruiter', function (): void {
    peopleMetric('recruiter.outcomes');
})->throws(InvalidArgumentException::class);

test('team outcomes are the same canonical metrics over the manager\'s hierarchy', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    peopleHire(['recruiter_id' => $recruiter->id]);
    peopleHire();

    $viewer = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');

    expect(peopleMetric('team.outcomes', $viewer)->value)->toBe(1.0)
        ->and(peopleMetric('team.outcomes', $viewer)->detail('components')['hiring.hires']['value'])->toBe(1.0);
});

test('requisitions at risk come from the current Hiring Health snapshots of open requisitions', function (): void {
    $critical = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
    $healthy = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    foreach ([[$critical, HealthStatus::Critical], [$healthy, HealthStatus::Healthy]] as [$requisition, $status]) {
        HiringHealthSnapshot::query()->create(['requisition_id' => $requisition->id, 'status' => $status, 'rules_version' => 'hiring-health/1', 'metrics' => [], 'breach_count' => 0, 'watch_count' => 0, 'completeness_pct' => 100, 'is_current' => true, 'computed_at' => now()]);
    }

    $result = app(MetricService::class)->get('requisition.hiring_health', MetricQuery::make(null, null));

    expect($result->value)->toBe(1.0)
        ->and($result->detail('not_assessed'))->toBe(1)
        ->and($result->detail('by_status.critical'))->toBe(1);
});

test('the Outcome Loop withholds averages, minimum and maximum below the minimum sample in the service', function (): void {
    $snapshot = HiringOutcomeSnapshot::factory()->create(['joined_on' => now()->toDateString()]);
    app(OutcomeService::class)->record(OutcomeType::TimeToHire, "snapshot:{$snapshot->id}:time_to_hire", [
        'result' => OutcomeResult::Measured, 'confidence' => OutcomeConfidence::High, 'value' => 12, 'unit' => 'days',
        'observed_at' => now(), 'hiring_outcome_snapshot_id' => $snapshot->id, 'requisition_id' => null,
    ]);

    $section = app(OutcomeAnalyticsService::class)->section('time_to_hire', null, ['from' => now()->subDays(5)->toDateString(), 'to' => now()->toDateString()]);

    expect($section['sample_size'])->toBe(1)
        ->and($section['value'])->toBeNull()
        ->and($section['average'])->toBeNull()
        ->and($section['min'])->toBeNull()
        ->and($section['max'])->toBeNull()
        ->and(peopleMetric('outcome.time_to_hire')->value)->toBeNull();
});

test('the Outcome Loop offer outcome leaves withdrawn offers out (D4)', function (): void {
    foreach ([OfferStatus::Accepted, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Withdrawn] as $status) {
        $offer = Offer::factory()->create(['status' => $status]);
        $offer->statusHistory()->create(['from_status' => OfferStatus::Initiated, 'to_status' => OfferStatus::Released]);
        $offer->statusHistory()->create(['from_status' => OfferStatus::Released, 'to_status' => $status]);
        app(OutcomeCalculator::class)->offer($offer->fresh());
    }

    $offers = app(OutcomeAnalyticsService::class)->section('offers', null, ['from' => now()->subDays(5)->toDateString(), 'to' => now()->toDateString()]);

    expect($offers['value'])->toBe(66.7)
        ->and($offers['sample_size'])->toBe(3)
        ->and($offers['excluded_withdrawn'])->toBe(1);
});
