<?php

namespace App\Services\Entitlements;

use App\Enums\Entitlement;
use DomainException;

/**
 * SaaS-3: the tenant's plan (or lifecycle) does not allow this. A DomainException, so every
 * existing "show the message, stop the action" path handles it; the message names the capability
 * and the next step, never plan codes or platform internals.
 */
class EntitlementDenied extends DomainException
{
    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly string $reason = 'not_in_plan',
        ?string $message = null,
    ) {
        parent::__construct($message ?? ($reason === 'tenant_inactive'
            ? 'Your organisation\'s account is not active, so this is not available. Contact your administrator.'
            : $entitlement->unavailableMessage()));
    }
}
