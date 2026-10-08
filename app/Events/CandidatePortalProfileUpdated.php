<?php

namespace App\Events;

use App\Models\CandidatePortalAccount;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A candidate changed their own profile in the portal (field names only — values are in the
 * candidate's audit log).
 */
class CandidatePortalProfileUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly CandidatePortalAccount $account,
        public readonly array $changedFields,
    ) {}
}
