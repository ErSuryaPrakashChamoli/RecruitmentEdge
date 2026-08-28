<?php

namespace App\Observers;

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
        $this->duplicateDetector->findMatches($candidate)->each(
            fn (array $match) => CandidateDuplicateMatch::query()->firstOrCreate([
                'candidate_id' => $candidate->id,
                'matched_candidate_id' => $match['candidate']->id,
                'match_type' => $match['type'],
            ]),
        );
    }
}
