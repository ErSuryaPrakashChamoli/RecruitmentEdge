<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\IncentiveAdjustmentType;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferRevisionStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferLetter;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\CandidateJoiningService;
use App\Services\IncentiveApprovalService;
use App\Services\OfferService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 8.9 (P89-DQ-001…005): a page, job or second click holds a copy of a record loaded before
 * someone else changed it. Each authoritative service must decide on the latest committed row — read
 * under its lock — never on the caller's stale copy. (The real two-transaction interleavings are
 * exercised against MySQL by tests/Concurrency.)
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('a second payment from a stale copy is refused (DQ-001)', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Payable, 'amount' => 1000]);
    $stale = RecruiterIncentiveCalculation::query()->find($calculation->id);
    $service = app(IncentiveApprovalService::class);

    $service->pay($calculation, 1000.0, now(), 'REF-1');

    expect(fn () => $service->pay($stale, 1000.0, now(), 'REF-2'))->toThrow(DomainException::class, 'Only a Payable incentive can be paid')
        ->and($calculation->payments()->count())->toBe(1)
        ->and($calculation->approvals()->where('to_status', IncentiveCalculationStatus::Paid->value)->count())->toBe(1);
});

test('a second reversal from a stale copy writes no second zeroing adjustment (DQ-001)', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Approved, 'amount' => 1000]);
    $stale = RecruiterIncentiveCalculation::query()->find($calculation->id);
    $service = app(IncentiveApprovalService::class);

    $service->reverse($calculation, 'Did not complete probation');

    expect(fn () => $service->reverse($stale, 'Clicked twice'))->toThrow(DomainException::class, 'Cannot reverse an incentive that is Reversed')
        ->and($calculation->adjustments()->where('adjustment_type', IncentiveAdjustmentType::Reversal->value)->count())->toBe(1)
        ->and($calculation->fresh()->effectiveAmount())->toBe(0.0);
});

test('an approval from a stale copy cannot override a rejection (DQ-001)', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::PendingVerification]);
    $stale = RecruiterIncentiveCalculation::query()->find($calculation->id);
    $service = app(IncentiveApprovalService::class);

    $service->reject($calculation, 'Not eligible');

    expect(fn () => $service->approve($stale))->toThrow(DomainException::class, 'from Rejected to Approved')
        ->and($calculation->fresh()->status)->toBe(IncentiveCalculationStatus::Rejected);
});

test('a dropout recorded from a stale copy cannot undo a join (DQ-003)', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::JoiningConfirmed]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Confirmed]);
    $stale = CandidateJoining::query()->find($joining->id);
    $service = app(CandidateJoiningService::class);

    $service->markJoined($joining, now()->subDay());

    expect(fn () => $service->markDropout($stale, RecruitmentRejectionReason::factory()->create()))->toThrow(DomainException::class, 'already Joined')
        ->and(fn () => $service->markJoined($stale, now()->addMonth()))->toThrow(DomainException::class, 'already Joined')
        ->and($joining->fresh()->status)->toBe(JoiningStatus::Joined)
        ->and($joining->fresh()->actual_doj->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('a revision cannot be released onto an offer accepted meanwhile (DQ-004)', function (): void {
    $releaser = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::OfferReleased]);
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Released, 'offered_ctc' => 1000000]);
    $service = app(OfferService::class);
    $revision = $service->requestRevision($offer, ['offered_ctc' => 1300000], 'Counter-offer', $releaser);
    $staleRevision = $revision->fresh();
    $staleRevision->setRelation('offer', Offer::query()->find($offer->id));

    $service->moveTo(Offer::query()->find($offer->id), OfferStatus::Accepted, $releaser->employee);

    expect(fn () => $service->releaseRevision($staleRevision, $releaser))->toThrow(DomainException::class, 'no longer released')
        ->and($offer->fresh()->offered_ctc)->toEqual('1000000.00')
        ->and($revision->fresh()->status)->toBe(OfferRevisionStatus::Pending)
        ->and(OfferLetter::query()->where('offer_id', $offer->id)->where('offer_revision_id', $revision->id)->exists())->toBeFalse();
});

test('a draft edit saved after the offer was released does not change its terms (DQ-004)', function (): void {
    $releaser = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Initiated, 'offered_ctc' => 1000000]);
    $openedForm = Offer::query()->find($offer->id);
    $service = app(OfferService::class);

    $service->moveTo($offer, OfferStatus::Released, $releaser->employee);

    expect(fn () => $service->updateTerms($openedForm, ['offered_ctc' => 1500000]))->toThrow(DomainException::class, 'only change through a revision')
        ->and($offer->fresh()->offered_ctc)->toEqual('1000000.00');
});

test('a stage move or second rejection from a stale copy is refused (DQ-005)', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened]);
    $stale = CandidateApplication::query()->find($application->id);
    $secondStale = CandidateApplication::query()->find($application->id);
    $reason = RecruitmentRejectionReason::factory()->create();
    $service = app(StageTransitionService::class);

    $service->reject($application, $reason);

    expect(fn () => $service->transitionTo($stale, CandidateStage::Shortlisted))->toThrow(DomainException::class, 'not active')
        ->and(fn () => $service->reject($secondStale, $reason))->toThrow(DomainException::class, 'already Rejected')
        ->and($application->stageHistory()->where('event', 'rejected')->count())->toBe(1)
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::Screened);
});

test('a stale copy cannot move an application backwards past a later move (DQ-005)', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened]);
    $stale = CandidateApplication::query()->find($application->id);
    $service = app(StageTransitionService::class);

    $service->transitionTo($application, CandidateStage::InterviewScheduled);

    expect(fn () => $service->transitionTo($stale, CandidateStage::Shortlisted))->toThrow(DomainException::class, 'backward')
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::InterviewScheduled);
});
