<?php

use App\Enums\IncentiveCalculationStatus;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\JoiningStatus;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use App\Services\IncentiveApprovalService;
use App\Services\RecruiterIncentiveCalculator;

beforeEach(function (): void {
    $this->calculator = app(RecruiterIncentiveCalculator::class);
});

function makeJoinedCandidate(?Employee $recruiter = null): CandidateJoining
{
    $recruiter ??= Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    return CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);
}

test('a flat-amount joining rule produces a calculation with the matching slab amount', function (): void {
    $joining = makeJoinedCandidate();

    $rule = RecruitmentIncentiveRule::factory()->create([
        'trigger_event' => IncentiveTriggerEvent::Joining,
        'achievement_metric' => null,
        'effective_from' => now()->subDay(),
    ]);
    RecruitmentIncentiveSlab::factory()->create([
        'incentive_rule_id' => $rule->id,
        'achievement_min' => 0,
        'achievement_max' => null,
        'amount' => 1000,
    ]);

    $results = $this->calculator->calculateForJoining($joining);

    expect($results)->toHaveCount(1);
    expect((float) $results->first()->amount)->toBe(1000.0)
        ->and($results->first()->status)->toBe(IncentiveCalculationStatus::PendingVerification);
});

test('an achievement-metric rule picks the slab matching the recruiter achievement %', function (): void {
    $joining = makeJoinedCandidate();
    $recruiter = $joining->candidateApplication->recruiter;

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Joining,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 1,
        'effective_from' => now()->startOfMonth(),
    ]);

    $rule = RecruitmentIncentiveRule::factory()->create([
        'trigger_event' => IncentiveTriggerEvent::Joining,
        'achievement_metric' => TargetMetric::Joining,
        'effective_from' => now()->subDay(),
    ]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 0, 'achievement_max' => 49, 'amount' => 500]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 50, 'achievement_max' => null, 'amount' => 2000]);

    $results = $this->calculator->calculateForJoining($joining);

    // 1 joined / target 1 = 100% achievement -> the 50%+ slab.
    expect($results)->toHaveCount(1);
    expect((float) $results->first()->amount)->toBe(2000.0)
        ->and((float) $results->first()->achievement)->toBe(100.0);
});

test('a rule scoped to a different recruiter does not apply', function (): void {
    $joining = makeJoinedCandidate();
    $otherRecruiter = Employee::factory()->create();

    $rule = RecruitmentIncentiveRule::factory()->create([
        'trigger_event' => IncentiveTriggerEvent::Joining,
        'employee_id' => $otherRecruiter->id,
        'effective_from' => now()->subDay(),
    ]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 0, 'achievement_max' => null, 'amount' => 1000]);

    $results = $this->calculator->calculateForJoining($joining);

    expect($results)->toHaveCount(0);
});

test('a rule with retention days holds the calculation at Calculated until it elapses', function (): void {
    $joining = makeJoinedCandidate();

    $rule = RecruitmentIncentiveRule::factory()->create([
        'trigger_event' => IncentiveTriggerEvent::Joining,
        'achievement_metric' => null,
        'retention_days' => 30,
        'effective_from' => now()->subDay(),
    ]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 0, 'achievement_max' => null, 'amount' => 1000]);

    $results = $this->calculator->calculateForJoining($joining);

    expect($results->first()->status)->toBe(IncentiveCalculationStatus::Calculated)
        ->and($results->first()->retention_due_at->toDateString())->toBe(now()->addDays(30)->toDateString());
});

test('recalculating for the same period updates the existing calculation rather than duplicating it', function (): void {
    $joining = makeJoinedCandidate();

    $rule = RecruitmentIncentiveRule::factory()->create([
        'trigger_event' => IncentiveTriggerEvent::Joining,
        'achievement_metric' => null,
        'effective_from' => now()->subDay(),
    ]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 0, 'achievement_max' => null, 'amount' => 1000]);

    $this->calculator->calculateForJoining($joining);
    $this->calculator->calculateForJoining($joining);

    expect(RecruiterIncentiveCalculation::query()->count())->toBe(1);
});

test('recalculating never touches a calculation that has already been approved', function (): void {
    $joining = makeJoinedCandidate();

    $rule = RecruitmentIncentiveRule::factory()->create([
        'trigger_event' => IncentiveTriggerEvent::Joining,
        'achievement_metric' => null,
        'effective_from' => now()->subDay(),
    ]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 0, 'achievement_max' => null, 'amount' => 1000]);

    $calculation = $this->calculator->calculateForJoining($joining)->first();
    app(IncentiveApprovalService::class)->moveTo($calculation, IncentiveCalculationStatus::Approved);

    // Slab amount changes; a naive recalculation would overwrite the approved figure.
    RecruitmentIncentiveSlab::query()->where('incentive_rule_id', $rule->id)->update(['amount' => 9999]);

    $results = $this->calculator->calculateForJoining($joining);

    expect((float) $results->first()->amount)->toBe(1000.0)
        ->and($results->first()->status)->toBe(IncentiveCalculationStatus::Approved);
});
