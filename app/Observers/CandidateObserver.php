<?php

namespace App\Observers;

use App\Events\DuplicateCandidateDetected;
use App\Models\Candidate;
use App\Models\CandidateDuplicateMatch;
use App\Services\CandidateDuplicateDetector;

class CandidateObserver
{
    public function __construct(private readonly CandidateDuplicateDetector $duplicateDetector) {}

    /**
     * Logs likely duplicates for HR review without blocking creation (Section 10).
     */
    public function created(Candidate $candidate): void
    {
        $this->logDuplicateMatches($candidate);
    }

    /**
     * A changed name, mobile or email can introduce a new duplicate, so re-run detection. One review
     * row per candidate pair (firstOrCreate on the pair, holding the strongest signal seen when it
     * was first logged), so re-running never duplicates a match or resets a review.
     */
    public function updated(Candidate $candidate): void
    {
        if ($candidate->wasChanged(['full_name', 'mobile', 'alternate_mobile', 'email'])) {
            $this->logDuplicateMatches($candidate);
        }
    }

    private function logDuplicateMatches(Candidate $candidate): void
    {
        $logged = $this->duplicateDetector->findMatches($candidate)
            ->map(fn (array $match) => CandidateDuplicateMatch::query()->firstOrCreate(
                ['candidate_id' => $candidate->id, 'matched_candidate_id' => $match['candidate']->id],
                ['match_type' => $match['type']],
            ))
            ->filter(fn (CandidateDuplicateMatch $match) => $match->wasRecentlyCreated)
            ->values();

        if ($logged->isNotEmpty()) {
            DuplicateCandidateDetected::dispatch($candidate, $logged);
        }
    }
}
