<?php

use App\Enums\CandidateStage;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\ReferralStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateSource;
use App\Models\CandidateTimelineEvent;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRequisition;
use App\Services\CandidateJoiningService;
use App\Services\RecruiterIncentiveCalculator;
use App\Services\ReferralService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concurrency\Race;

/**
 * Phase 8.10 (P810-DI-01, DI-02, DI-04): real two-transaction races on MySQL 8.4 / REPEATABLE READ,
 * as in IntegrityRaceTest. Each must fail on the pre-fix code.
 *
 * Run with: vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');

    if (! DB::table('roles')->exists()) {
        $this->seed(RolePermissionSeeder::class);
    }
});

test('two calculations of one selection priced in different months record one calculation (DI-01)', function (): void {
    $rule = RecruitmentIncentiveRule::factory()->fixed(1000)->create(['trigger_event' => IncentiveTriggerEvent::Selection, 'effective_from' => now()->subYear()]);
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);

    $race = Race::run(
        holder: fn () => app(RecruiterIncentiveCalculator::class)->calculateForSelection(CandidateApplication::query()->find($application->id), now()),
        contender: fn () => app(RecruiterIncentiveCalculator::class)->calculateForSelection(CandidateApplication::query()->find($application->id), now()->addMonth()),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and(RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $rule->id)->count())->toBe(1);
});

test('a late referral sync racing the join cannot move the joined referral backwards (DI-02)', function (): void {
    CandidateSource::query()->firstOrCreate(['code' => CandidateSource::CODE_EMPLOYEE_REFERRAL], ['name' => 'Employee Referral']);
    RecruitmentIncentiveRule::factory()->fixed(5000)->create(['trigger_event' => IncentiveTriggerEvent::ReferralJoining, 'effective_from' => now()->subYear()]);
    $referrals = app(ReferralService::class);
    $referral = $referrals->accept(
        $referrals->submit(Employee::factory()->create(), ['full_name' => 'Race Referral', 'mobile' => '9'.random_int(100000000, 999999999)], ['relationship' => 'friend'], RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open])),
        Employee::factory()->create(),
    );
    $application = CandidateApplication::query()->find($referral->candidate_application_id);
    $application->forceFill(['current_stage' => CandidateStage::Selected])->saveQuietly();
    $referral->forceFill(['status' => ReferralStatus::Selected])->saveQuietly();
    $joining = app(CandidateJoiningService::class)->createForAcceptedOffer(Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Accepted]));

    // The late sync's event carries the application as it was at Offer Released.
    $offerReleasedCopy = $application->fresh()->forceFill(['current_stage' => CandidateStage::OfferReleased]);
    $joining->forceFill(['status' => JoiningStatus::Joined, 'actual_doj' => now()])->saveQuietly();
    $application->forceFill(['current_stage' => CandidateStage::Joined])->saveQuietly();

    $race = Race::run(
        holder: fn () => app(ReferralService::class)->syncFromApplication(EmployeeReferral::query()->find($referral->id), CandidateApplication::query()->find($application->id)),
        contender: fn () => app(ReferralService::class)->syncFromApplication(EmployeeReferral::query()->find($referral->id), $offerReleasedCopy),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and($referral->fresh()->status)->toBe(ReferralStatus::Joined)
        ->and(CandidateTimelineEvent::query()->where('candidate_id', $referral->candidate_id)->where('title', 'like', "Referral {$referral->referral_code}:%→ Joined")->count())->toBe(1)
        ->and(RecruiterIncentiveCalculation::query()->where('employee_referral_id', $referral->id)->count())->toBe(1);
});
