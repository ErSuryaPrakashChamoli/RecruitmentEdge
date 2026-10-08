<?php

namespace App\Events;

use App\Models\Candidate;
use App\Models\Employee;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user created a new candidate despite strong duplicate matches, with a justification. The
 * AuditLog `duplicate_override` row is the authoritative record; this is the integration hook.
 */
class DuplicateOverrideApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $matches  DuplicateCandidateMatch::toArray() rows
     */
    public function __construct(
        public readonly Candidate $candidate,
        public readonly array $matches,
        public readonly string $justification,
        public readonly ?Employee $actor = null,
    ) {}
}
