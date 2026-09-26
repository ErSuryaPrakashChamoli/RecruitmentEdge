<?php

namespace App\Events;

use App\Models\CandidatePortalAccount;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A candidate changed their own profile in the portal (field names only — values are in the
 * candidate's audit log).
 */
class CandidatePortalProfileUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly CandidatePortalAccount $account,
        public readonly array $changedFields,
    ) {}
}
