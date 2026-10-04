<?php

namespace App\Services\Billing;

use App\Models\User;
use DomainException;

/**
 * SaaS-4: tenant billing permissions, separate from every other administration right — a tenant
 * administrator is not a billing administrator unless granted. billing.view: the organisation's
 * subscription, invoices and payments. billing.manage: billing details and contact, cancelling or
 * resuming at the end of the period. Plans, prices, refunds and immediate cancellation are platform
 * operations (PlatformOperatorGate), never a tenant's.
 */
final class BillingAuthorization
{
    public static function canView(?User $user): bool
    {
        return $user !== null && ($user->can('billing.view') || $user->can('billing.manage'));
    }

    public static function assertCanManage(User $actor): void
    {
        if (! $actor->can('billing.manage')) {
            throw new DomainException('Managing billing needs the billing.manage permission.');
        }
    }
}
