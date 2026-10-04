<?php

namespace App\Services\Entitlements;

use App\Enums\Entitlement;

/**
 * SaaS-3: a limit would be exceeded. Carries what the screen may show: the capability, the limit
 * and the current usage.
 */
class LimitReached extends EntitlementDenied
{
    public function __construct(
        Entitlement $entitlement,
        public readonly int $limit,
        public readonly int $usage,
    ) {
        parent::__construct($entitlement, 'limit_reached', $entitlement->unavailableMessage()." (In use: {$usage} of {$limit}.)");
    }
}
