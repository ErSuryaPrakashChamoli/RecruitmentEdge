<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\Interview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by InterviewService::complete() after the result and the resulting stage change commit
 * (Phase 6 automation trigger).
 */
class InterviewCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Interview $interview,
        public readonly ?Employee $actor = null,
    ) {}
}
