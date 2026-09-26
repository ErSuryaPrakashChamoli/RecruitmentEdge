<?php

use App\Enums\OfferStatus;
use App\Enums\OutcomeCategory;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;

test('the taxonomy only contains outcome types with real source data', function (): void {
    $values = array_map(fn (OutcomeType $type) => $type->value, OutcomeType::cases());

    expect($values)->not->toContain('performance_success')
        ->not->toContain('probation_completed')
        ->not->toContain('promotion')
        ->not->toContain('quality_of_hire')
        ->and(array_keys(OutcomeType::UNAVAILABLE))->toBe(['performance', 'attendance', 'probation', 'promotion', 'historical_retention']);
});

test('status observations are the only retention outcomes, carry their window, and are never backfilled', function (): void {
    expect(array_map(fn (OutcomeType $type) => $type->windowDays(), OutcomeType::statusObservations()))->toBe([30, 90, 180])
        ->and(OutcomeType::forWindow(90))->toBe(OutcomeType::StatusObserved90d)
        ->and(collect(OutcomeType::statusObservations())->every(fn (OutcomeType $type) => $type->category() === OutcomeCategory::Retention && ! $type->isBackfillable()))->toBeTrue()
        ->and(OutcomeType::Joined->isBackfillable())->toBeTrue()
        ->and(OutcomeType::StatusObserved90d->definition())->toContain('Not confirmed retention');
});

test('each offer status maps to its own outcome and draft stages map to none', function (): void {
    expect(OutcomeType::forOfferStatus(OfferStatus::Released))->toBe(OutcomeType::OfferReleased)
        ->and(OutcomeType::forOfferStatus(OfferStatus::Accepted))->toBe(OutcomeType::OfferAccepted)
        ->and(OutcomeType::forOfferStatus(OfferStatus::Withdrawn))->toBe(OutcomeType::OfferWithdrawn)
        ->and(OutcomeType::forOfferStatus(OfferStatus::Draft))->toBeNull();
});

test('missing evidence is never an observed result', function (OutcomeResult $result, bool $observed): void {
    expect($result->isObserved())->toBe($observed);
})->with([
    [OutcomeResult::NotObserved, false], [OutcomeResult::Unknown, false], [OutcomeResult::NotApplicable, false],
    [OutcomeResult::Active, true], [OutcomeResult::SeparatedBeforeCheckpoint, true], [OutcomeResult::Occurred, true],
]);

test('the state machine only allows the documented moves', function (OutcomeState $from, OutcomeState $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    [OutcomeState::Pending, OutcomeState::Observed, true],
    [OutcomeState::Observed, OutcomeState::Confirmed, true],
    [OutcomeState::Observed, OutcomeState::Pending, false],
    [OutcomeState::Confirmed, OutcomeState::Observed, false],
    [OutcomeState::Void, OutcomeState::Observed, false],
    [OutcomeState::Unknown, OutcomeState::Observed, true],
]);
