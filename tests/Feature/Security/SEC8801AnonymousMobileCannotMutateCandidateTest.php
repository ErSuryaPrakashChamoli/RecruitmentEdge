<?php

use App\Models\Candidate;
use App\Models\CandidateApplication;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Sec88ContainmentHelpers.php';

/**
 * SEC-88-01: an anonymous submission that knows an existing candidate's MOBILE (exact, normalised
 * or their alternate number) must not change anything about that candidate.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->posting = sec88Posting();
    $this->victim = sec88ExistingCandidate();
});

test('a submission using an existing candidate\'s mobile leaves that candidate untouched', function (string $mobile): void {
    $before = sec88Snapshot($this->victim);

    sec88Apply($this->posting, ['mobile' => $mobile]);
    sec88Apply($this->posting, ['mobile' => $mobile]);

    expect(sec88Snapshot($this->victim))->toEqual($before)
        ->and(Candidate::query()->count())->toBe(1)
        ->and(CandidateApplication::query()->count())->toBe(0);
})->with([
    'exact' => '9811122233',
    'normalised with country code' => '+91 98111 22233',
]);

test('a submission using an existing candidate\'s alternate mobile leaves that candidate untouched', function (): void {
    $this->victim->update(['alternate_mobile' => '9700000077']);
    $before = sec88Snapshot($this->victim);

    sec88Apply($this->posting, ['mobile' => '9700000077']);

    expect(sec88Snapshot($this->victim))->toEqual($before)
        ->and(CandidateApplication::query()->count())->toBe(0);
});
