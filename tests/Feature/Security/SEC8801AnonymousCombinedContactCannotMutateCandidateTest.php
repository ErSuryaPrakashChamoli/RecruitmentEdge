<?php

use App\Models\Candidate;
use App\Models\CandidateApplication;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Sec88ContainmentHelpers.php';

/**
 * SEC-88-01: combining contact details (and the candidate's name) is still not proof of identity.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->posting = sec88Posting();
    $this->victim = sec88ExistingCandidate();
});

test('any combination of matching contact details leaves the existing candidate untouched, twice over', function (array $variant): void {
    $before = sec88Snapshot($this->victim);

    sec88Apply($this->posting, $variant);
    sec88Apply($this->posting, $variant);

    expect(sec88Snapshot($this->victim))->toEqual($before)
        ->and(Candidate::query()->count())->toBe(1)
        ->and(CandidateApplication::query()->count())->toBe(0);
})->with(sec88MatchingVariants());
