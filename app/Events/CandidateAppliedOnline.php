<?php

namespace App\Events;

use App\Models\CandidateApplication;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A candidate applied through the career site (Phase 5) — drives the "application received"
 * communication and recruiter notification.
 */
class CandidateAppliedOnline implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly CandidateApplication $application) {}
}
