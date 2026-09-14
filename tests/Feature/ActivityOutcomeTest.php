<?php

use App\Enums\ActivityOutcome;

test('not reachable and not interested outcomes exist with labels and are not counted as connected', function (ActivityOutcome $outcome, string $value, string $label): void {
    expect($outcome->value)->toBe($value)
        ->and($outcome->label())->toBe($label)
        ->and($outcome->isConnected())->toBeFalse();
})->with([
    [ActivityOutcome::NotReachable, 'not_reachable', 'Not Reachable'],
    [ActivityOutcome::NotInterested, 'not_interested', 'Not Interested'],
]);

test('every activity outcome has a badge color', function (): void {
    foreach (ActivityOutcome::cases() as $outcome) {
        expect($outcome->color())->toBeString()->not->toBeEmpty();
    }
});
