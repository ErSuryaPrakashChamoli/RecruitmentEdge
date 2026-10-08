<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyRecord;

/**
 * Phase 8.6 (D8.6-027): read-only history, visible within its owner record's page.
 */
class CandidateDuplicateMatchPolicy
{
    use ReadOnlyRecord;
}
