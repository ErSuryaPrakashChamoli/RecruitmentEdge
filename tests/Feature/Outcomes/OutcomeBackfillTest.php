<?php

use App\Enums\EmployeeStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeCategory;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeType;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use App\Models\OfferStatusHistory;
use App\Models\RecruitmentRequisition;
use App\Services\Outcomes\OutcomeEvaluator;

function outcomeBackfillJoining(int $joinedDaysAgo, ?RecruitmentRequisition $requisition = null): CandidateJoining
{
    $application = CandidateApplication::factory()->create(['requisition_id' => ($requisition ?? RecruitmentRequisition::factory()->create())->id, 'application_date' => now()->subDays($joinedDaysAgo + 30)]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->subDays($joinedDaysAgo)->toDateString()]);
    CandidateJoining::query()->whereKey($joining->id)->update(['updated_at' => now()->subDays($joinedDaysAgo)]);

    return $joining->fresh();
}

test('backfill is a dry run by default and records nothing', function (): void {
    outcomeBackfillJoining(400);

    $this->artisan('outcomes:backfill')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('joining outcomes: 1 would be recorded')
        ->assertSuccessful();

    expect(HiringOutcome::query()->count())->toBe(0)
        ->and(HiringOutcomeSnapshot::query()->count())->toBe(0);
});

test('the daily evaluation leaves old history to the backfill and catches up recent events only', function (): void {
    $old = outcomeBackfillJoining(400);
    $recent = outcomeBackfillJoining(2);

    app(OutcomeEvaluator::class)->evaluate();

    expect(HiringOutcome::query()->where('candidate_joining_id', $old->id)->exists())->toBeFalse()
        ->and(HiringOutcome::query()->where('candidate_joining_id', $recent->id)->where('category', OutcomeCategory::Joining)->sole()->capture_mode)->toBe(OutcomeCaptureMode::ObservedGoingForward)
        ->and(HiringOutcomeSnapshot::query()->where('candidate_joining_id', $recent->id)->exists())->toBeTrue();
});

test('executing backfills deterministic outcomes, labelled as backfilled, and never retention', function (): void {
    $joining = outcomeBackfillJoining(400);
    $employee = Employee::factory()->create(['candidate_id' => $joining->candidateApplication->candidate_id, 'status' => EmployeeStatus::Active]);
    AuditLog::record($employee, 'updated', ['status' => 'active'], ['status' => 'inactive']);
    $offer = Offer::factory()->create(['candidate_application_id' => $joining->candidate_application_id, 'status' => OfferStatus::Accepted]);
    OfferStatusHistory::query()->forceCreate(['offer_id' => $offer->id, 'to_status' => OfferStatus::Released, 'created_at' => now()->subDays(450)]);
    OfferStatusHistory::query()->forceCreate(['offer_id' => $offer->id, 'from_status' => OfferStatus::Released, 'to_status' => OfferStatus::Accepted, 'created_at' => now()->subDays(445)]);

    $this->artisan('outcomes:backfill --execute')->expectsOutputToContain('BACKFILLED_DETERMINISTIC')->assertSuccessful();
    $snapshot = HiringOutcomeSnapshot::query()->sole();

    expect(HiringOutcome::query()->pluck('outcome_type')->map->value->sort()->values()->all())->toBe(['joined', 'offer_accepted', 'offer_released', 'time_in_stage', 'time_to_hire'])
        ->and(HiringOutcome::query()->pluck('capture_mode')->unique()->all())->toBe([OutcomeCaptureMode::BackfilledDeterministic])
        ->and($snapshot->capture_mode)->toBe(OutcomeCaptureMode::BackfilledDeterministic)
        ->and($snapshot->role_dna_version_id)->toBeNull()
        ->and(HiringOutcome::query()->where('category', OutcomeCategory::Retention)->exists())->toBeFalse();

    app(OutcomeEvaluator::class)->evaluate();

    expect($snapshot->employee_id)->toBe($employee->id)
        ->and(HiringOutcome::query()->where('category', OutcomeCategory::Retention)->pluck('result')->unique()->all())->toBe([OutcomeResult::NotObserved])
        ->and(HiringOutcome::query()->where('outcome_type', OutcomeType::StatusObserved30d)->sole()->details['reason'])->toBe('checkpoint_before_observation_started');
});

test('backfill is idempotent and never relabels outcomes observed going forward', function (): void {
    $recent = outcomeBackfillJoining(2);
    app(OutcomeEvaluator::class)->evaluate();
    outcomeBackfillJoining(400);

    $this->artisan('outcomes:backfill --execute')->assertSuccessful();
    $afterFirst = HiringOutcome::query()->count();
    $this->artisan('outcomes:backfill --execute')->assertSuccessful();

    expect(HiringOutcome::query()->count())->toBe($afterFirst)
        ->and(HiringOutcome::query()->where('supersedes_id', '!=', null)->exists())->toBeFalse()
        ->and(HiringOutcome::query()->where('candidate_joining_id', $recent->id)->where('category', OutcomeCategory::Joining)->sole()->capture_mode)->toBe(OutcomeCaptureMode::ObservedGoingForward);
});

test('the date range and requisition options narrow the backfill', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    $inRange = outcomeBackfillJoining(300, $requisition);
    outcomeBackfillJoining(600, $requisition);
    outcomeBackfillJoining(300);

    $this->artisan('outcomes:backfill', ['--execute' => true, '--from' => now()->subDays(365)->toDateString(), '--to' => now()->subDays(30)->toDateString(), '--requisition' => $requisition->id])->assertSuccessful();

    expect(HiringOutcome::query()->where('category', OutcomeCategory::Joining)->pluck('candidate_joining_id')->all())->toBe([$inRange->id])
        ->and(HiringOutcomeSnapshot::query()->pluck('candidate_joining_id')->all())->toBe([$inRange->id]);
});

test('an invalid date is refused', function (): void {
    $this->artisan('outcomes:backfill --from=not-a-date')->expectsOutputToContain('must be dates')->assertFailed();
});
