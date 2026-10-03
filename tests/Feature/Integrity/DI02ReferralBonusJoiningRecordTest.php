<?php

use App\Enums\CandidateStage;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Services\CandidateJoiningService;
use App\Services\RecruiterIncentiveCalculator;
use App\Services\ReferralService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;

/*
 * Phase 8.10 (P810-DI-02): a referral becomes Joined, and its bonus is priced, only on the
 * application's joining record being Joined — never on the pipeline stage alone — dated by that
 * record. One bonus per hire, whatever happens later; referrals written before the rule stay as
 * they are.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    CandidateSource::factory()->create(['name' => 'Employee Referral', 'code' => CandidateSource::CODE_EMPLOYEE_REFERRAL]);
    $this->rule = RecruitmentIncentiveRule::factory()->fixed(5000)->create(['trigger_event' => IncentiveTriggerEvent::ReferralJoining, 'effective_from' => now()->subYear()]);

    $service = app(ReferralService::class);
    $submitted = $service->submit(Employee::factory()->create(), ['full_name' => 'Kiran Rao', 'mobile' => '9876501234'], ['relationship' => 'friend'], RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]));
    $this->referral = $service->accept($submitted, Employee::factory()->create());
    $this->application = $this->referral->candidateApplication;
    $this->joinings = app(CandidateJoiningService::class);
});

function referralJoining(EmployeeReferral $referral): CandidateJoining
{
    return app(CandidateJoiningService::class)->createForAcceptedOffer(Offer::factory()->create(['candidate_application_id' => $referral->candidate_application_id, 'status' => OfferStatus::Accepted]));
}

function referralBonuses(): int
{
    return RecruiterIncentiveCalculation::query()->where('incentive_rule_id', test()->rule->id)->count();
}

test('a joining marked Joined makes the referral Joined and prices one bonus dated by the joining', function (): void {
    $this->joinings->markJoined(referralJoining($this->referral), now()->subDays(3));
    $referral = $this->referral->fresh();
    $bonus = RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $this->rule->id)->sole();

    expect($referral->status)->toBe(ReferralStatus::Joined)
        ->and($referral->joining_date->toDateString())->toBe(now()->subDays(3)->toDateString())
        ->and($referral->incentive_status)->toBe(ReferralIncentiveStatus::Calculated)
        ->and($bonus->period_start->toDateString())->toBe(now()->subDays(3)->startOfMonth()->toDateString())
        ->and((float) $bonus->amount)->toBe(5000.0);
});

test('reaching the Joined stage without a Joined joining record neither marks the referral Joined nor prices a bonus', function (Closure $joiningState): void {
    $joiningState($this->referral);

    app(StageTransitionService::class)->transitionTo($this->application, CandidateStage::Joined);

    expect($this->referral->fresh()->status)->not->toBe(ReferralStatus::Joined)
        ->and($this->referral->fresh()->joining_date)->toBeNull()
        ->and(referralBonuses())->toBe(0);
})->with([
    'no joining record' => [fn (EmployeeReferral $referral) => null],
    'a joining not yet joined' => [fn (EmployeeReferral $referral) => referralJoining($referral)],
]);

test('a rejected application prices no bonus', function (): void {
    referralJoining($this->referral);
    app(StageTransitionService::class)->reject($this->application, RecruitmentRejectionReason::factory()->create());

    expect($this->referral->fresh()->status)->toBe(ReferralStatus::Rejected)
        ->and(referralBonuses())->toBe(0);
});

test('a joining dropout marks the referral did-not-join, forfeits the bonus and prices nothing', function (): void {
    $this->joinings->markDropout(referralJoining($this->referral), RecruitmentRejectionReason::factory()->create());

    expect($this->referral->fresh()->status)->toBe(ReferralStatus::DidNotJoin)
        ->and($this->referral->fresh()->incentive_status)->toBe(ReferralIncentiveStatus::Forfeited)
        ->and(referralBonuses())->toBe(0);
});

test('later stage changes, a rejection and a reactivation in another month never price a second bonus', function (): void {
    $joining = referralJoining($this->referral);
    $this->joinings->markJoined($joining, now());
    $first = RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $this->rule->id)->sole();

    $this->joinings->markDocumentsCompleted($joining->fresh());
    $this->travel(2)->months();
    $stages = app(StageTransitionService::class);
    $stages->reject($this->application->fresh(), RecruitmentRejectionReason::factory()->create());
    $stages->reactivate($this->application->fresh());

    expect(referralBonuses())->toBe(1)
        ->and(RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $this->rule->id)->sole()->id)->toBe($first->id)
        ->and($this->referral->fresh()->joining_date->toDateString())->toBe($joining->fresh()->actual_doj->toDateString());
});

test('a referral already Joined on the stage alone before this rule is left as it is', function (): void {
    $historical = EmployeeReferral::query()->find($this->referral->id);
    $historical->forceFill(['status' => ReferralStatus::Joined, 'joining_date' => now()->subMonth()->toDateString(), 'incentive_status' => ReferralIncentiveStatus::Calculated])->save();
    $calculation = RecruiterIncentiveCalculation::factory()->create(['incentive_rule_id' => $this->rule->id, 'candidate_application_id' => $this->application->id, 'employee_referral_id' => $historical->id, 'status' => IncentiveCalculationStatus::Calculated, 'amount' => 5000]);
    $this->application->forceFill(['current_stage' => CandidateStage::Joined])->saveQuietly();

    app(ReferralService::class)->syncFromApplication($historical->fresh(), $this->application->fresh());

    expect($historical->fresh()->status)->toBe(ReferralStatus::Joined)
        ->and($historical->fresh()->joining_date->toDateString())->toBe(now()->subMonth()->toDateString())
        ->and(RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $this->rule->id)->sole()->id)->toBe($calculation->id)
        ->and((float) $calculation->fresh()->amount)->toBe(5000.0);
});

test('a referral bonus cannot be priced directly for a candidate who has not joined', function (): void {
    referralJoining($this->referral);
    $this->referral->forceFill(['status' => ReferralStatus::Joined])->save();

    expect(fn () => app(RecruiterIncentiveCalculator::class)->calculateForReferralJoining($this->referral->fresh()))
        ->toThrow(DomainException::class, 'has joined')
        ->and(referralBonuses())->toBe(0);
});

test('the referral reads the stored joining record, not a copy cached on the application', function (): void {
    $joining = referralJoining($this->referral);
    $staleApplication = $this->application->fresh()->load('joining');
    $joining->forceFill(['status' => JoiningStatus::Joined, 'actual_doj' => now()])->saveQuietly();

    app(ReferralService::class)->syncFromApplication($this->referral->fresh(), $staleApplication);

    expect($staleApplication->joining->status)->toBe(JoiningStatus::Expected)
        ->and($this->referral->fresh()->status)->toBe(ReferralStatus::Joined)
        ->and(referralBonuses())->toBe(1);
});

test('a late sync holding an application copy from before the rejection does not reopen the referral', function (): void {
    $staleApplication = $this->application->fresh();
    app(StageTransitionService::class)->reject($this->application->fresh(), RecruitmentRejectionReason::factory()->create());

    app(ReferralService::class)->syncFromApplication($this->referral->fresh(), $staleApplication);

    expect($this->referral->fresh()->status)->toBe(ReferralStatus::Rejected);
});
