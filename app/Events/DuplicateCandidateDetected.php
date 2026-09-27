<?php

namespace App\Events;

use App\Models\Candidate;
use App\Models\CandidateDuplicateMatch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * New possible-duplicate review rows were logged for a candidate (CandidateObserver). Hook for the
 * candidate timeline and future Notification Center / AI duplicate intelligence.
 */
class DuplicateCandidateDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Collection<int, CandidateDuplicateMatch>  $matches
     */
    public function __construct(
        public readonly Candidate $candidate,
        public readonly Collection $matches,
    ) {}
}
