<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use App\Models\OfferStatusHistory;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\Outcomes\OutcomeCalculator;
use App\Services\Outcomes\OutcomeEvaluator;
use App\Services\Outcomes\OutcomeService;

beforeEach(function (): void {
    $this->outcomes = app(OutcomeService::class);
    $this->hr = User::factory()->create();
});

function outcomeEngineRecord(OutcomeResult $result, string $key = 'test:1'): HiringOutcome
{
    return app(OutcomeService::class)->record(OutcomeType::Joined, $key, ['result' => $result, 'confidence' => OutcomeConfidence::High]);
}

test('recording the same outcome twice is a no-op', function (): void {
    outcomeEngineRecord(OutcomeResult::Occurred);
    outcomeEngineRecord(OutcomeResult::Occurred);

    expect(HiringOutcome::query()->count())->toBe(1);
});

test('a changed automatic result supersedes the current version and keeps the old one', function (): void {
    $first = outcomeEngineRecord(OutcomeResult::Occurred);
    $second = outcomeEngineRecord(OutcomeResult::Unknown);

    expect($first->fresh()->is_current)->toBeFalse()
        ->and($second->version)->toBe(2)
        ->and($second->supersedes_id)->toBe($first->id)
        ->and($second->state)->toBe(OutcomeState::Unknown)
        ->and(AuditLog::query()->where('action', 'outcome_superseded')->where('auditable_id', $second->id)->exists())->toBeTrue();
});

test('a correction needs a reason, keeps history, is audited, and later recalculation never overrides it', function (): void {
    $outcome = outcomeEngineRecord(OutcomeResult::Occurred);

    expect(fn () => $this->outcomes->correct($outcome, OutcomeResult::NotObserved, null, '  ', $this->hr))->toThrow(DomainException::class, 'reason');

    $corrected = $this->outcomes->correct($outcome, OutcomeResult::NotObserved, null, 'Joining was recorded against the wrong candidate', $this->hr);
    $audit = AuditLog::query()->where('action', 'outcome_corrected')->sole();

    expect($corrected->capture_mode)->toBe(OutcomeCaptureMode::ManualCorrection)
        ->and($corrected->corrected_by)->toBe($this->hr->id)
        ->and($outcome->fresh()->is_current)->toBeFalse()
        ->and($audit->old_values['result'])->toBe('occurred')
        ->and($audit->changes['result'])->toBe('not_observed')
        ->and($audit->changes['reason'])->toBe('Joining was recorded against the wrong candidate')
        ->and(outcomeEngineRecord(OutcomeResult::Occurred)->id)->toBe($corrected->id);
});

test('state changes follow the state machine', function (): void {
    $voided = $this->outcomes->void(outcomeEngineRecord(OutcomeResult::Occurred), 'Duplicate joining record', $this->hr);

    expect($voided->state)->toBe(OutcomeState::Void)
        ->and(fn () => $this->outcomes->confirm($voided, $this->hr))->toThrow(DomainException::class, 'cannot move')
        ->and($this->outcomes->confirm(outcomeEngineRecord(OutcomeResult::Occurred, 'test:2'), $this->hr)->state)->toBe(OutcomeState::Confirmed);
});

test('an unknown outcome is resolved by a correction, never by a bare confirmation', function (): void {
    $unknown = outcomeEngineRecord(OutcomeResult::NotObserved, 'test:unknown');

    expect($unknown->state)->toBe(OutcomeState::Unknown)
        ->and(fn () => $this->outcomes->confirm($unknown, $this->hr))->toThrow(DomainException::class, 'correction')
        ->and($this->outcomes->correct($unknown, OutcomeResult::Active, null, 'Checked with the business unit', $this->hr)->state)->toBe(OutcomeState::Confirmed);
});

test('joining outcomes come from the joining record, never from the pipeline stage alone', function (): void {
    $stageOnly = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined]);
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()->subDay()]);

    app(OutcomeEvaluator::class)->evaluate();
    $outcome = HiringOutcome::query()->where('candidate_joining_id', $joining->id)->sole();

    expect($outcome->outcome_type)->toBe(OutcomeType::Joined)
        ->and($outcome->confidence)->toBe(OutcomeConfidence::High)
        ->and($outcome->source_type)->toBe($joining->getMorphClass())
        ->and($outcome->rule_version)->toBe(OutcomeService::RULE_VERSION)
        ->and(HiringOutcome::query()->where('candidate_application_id', $stageOnly->id)->exists())->toBeFalse();
});

test('no-show and dropout keep the structured reason but no free-text remarks', function (): void {
    $reason = RecruitmentRejectionReason::factory()->create(['name' => 'Accepted another offer']);
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::NoShow, 'dropout_reason_id' => $reason->id, 'remarks' => 'PRIVATE-JOINING-REMARK']);

    $outcome = app(OutcomeCalculator::class)->joining($joining);

    expect($outcome->outcome_type)->toBe(OutcomeType::NoShow)
        ->and($outcome->details['reason'])->toBe('Accepted another offer')
        ->and(json_encode($outcome->details))->not->toContain('PRIVATE-JOINING-REMARK');
});

test('each offer status reached is its own outcome; acceptance is not joining', function (): void {
    $offer = Offer::factory()->create(['status' => OfferStatus::Accepted]);
    OfferStatusHistory::query()->create(['offer_id' => $offer->id, 'from_status' => OfferStatus::Initiated, 'to_status' => OfferStatus::Released]);
    OfferStatusHistory::query()->create(['offer_id' => $offer->id, 'from_status' => OfferStatus::Released, 'to_status' => OfferStatus::Accepted]);

    app(OutcomeCalculator::class)->offer($offer);

    expect(HiringOutcome::query()->pluck('outcome_type')->map->value->sort()->values()->all())->toBe(['offer_accepted', 'offer_released'])
        ->and(HiringOutcome::query()->where('outcome_type', OutcomeType::Joined)->exists())->toBeFalse();
});

test('process outcomes come from the snapshot taken at the join', function (): void {
    $snapshot = HiringOutcomeSnapshot::factory()->create([
        'time_to_hire_days' => 21,
        'facts' => ['time_to_hire' => ['start_point' => 'candidate_applied', 'start_date' => '2026-01-01', 'days' => 21], 'stage_days' => ['sourced' => 2.0, 'screened' => 5.5]],
    ]);

    [$timeToHire, $timeInStage] = app(OutcomeCalculator::class)->process($snapshot);

    expect((float) $timeToHire->value)->toBe(21.0)
        ->and($timeToHire->details['start_point'])->toBe('candidate_applied')
        ->and((float) $timeInStage->value)->toBe(7.5)
        ->and($timeInStage->details['stage_days'])->toEqual(['sourced' => 2.0, 'screened' => 5.5]);
});

test('evaluating twice records nothing new and a dry run records nothing at all', function (): void {
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()->subDay()]);
    $offer = Offer::factory()->create();
    OfferStatusHistory::query()->create(['offer_id' => $offer->id, 'from_status' => OfferStatus::Initiated, 'to_status' => OfferStatus::Released]);

    expect(app(OutcomeEvaluator::class)->evaluate(dryRun: true))->toMatchArray(['joining_outcomes' => 1, 'offers' => 1])
        ->and(HiringOutcome::query()->count())->toBe(0);

    app(OutcomeEvaluator::class)->evaluate();
    $afterFirst = HiringOutcome::query()->orderBy('id')->get(['id', 'dedupe_key', 'version'])->toArray();
    $second = app(OutcomeEvaluator::class)->evaluate();

    expect($second)->toMatchArray(['joining_outcomes' => 0, 'offers' => 0, 'process_outcomes' => 0])
        ->and(HiringOutcome::query()->orderBy('id')->get(['id', 'dedupe_key', 'version'])->toArray())->toBe($afterFirst);
});
