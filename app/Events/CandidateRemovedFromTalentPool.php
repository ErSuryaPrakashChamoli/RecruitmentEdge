<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\TalentPoolMembership;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by TalentPoolService after the membership change commits — the hook for Phase 5/6
 * re-engagement campaigns and Phase 7 Talent Rediscovery signals.
 */
class CandidateRemovedFromTalentPool implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly TalentPoolMembership $membership,
        public readonly ?Employee $actor = null,
    ) {}
}
