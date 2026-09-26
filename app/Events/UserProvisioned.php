<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 8.4: a staff login was created (IdentityProvisioningService) — by an administrator, by
 * candidate conversion or by a rehire. Ids and the source only.
 */
class UserProvisioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $employeeId,
        public readonly string $source,
        public readonly ?int $actorId,
    ) {}
}
