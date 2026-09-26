<?php

namespace App\Events;

use App\Models\Interview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by InterviewService::confirm() after the change commits (Phase 6) — lets automation rules
 * react to a confirmation (and escalation stop conditions see it on their next check).
 */
class InterviewConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Interview $interview) {}
}
