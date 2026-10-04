<?php

namespace App\Enums;

/**
 * SaaS-4: the canonical state of a commercial subscription. Provider states are mapped into these
 * by the provider adapter and never used directly (docs/saas-4-billing.md §5). A tenant has at
 * most one live (non-terminal) subscription.
 *
 * - Pending: created, awaiting its first payment (or contract activation); grants nothing yet.
 * - Trialing: during the tenant's SaaS-3 trial; the trial itself stays authoritative.
 * - Active: paid up (or under an active contract).
 * - PastDue: a payment failed; the grace period runs, the tenant stays usable until it ends.
 * - Unpaid: the grace period ended unpaid; access is suspended, data kept, payment restores it.
 * - Cancelling: ends at the end of the paid period unless resumed.
 * - Cancelled / Expired: ended (cancelled by someone, or never paid); terminal.
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting first payment',
            self::Trialing => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Payment overdue',
            self::Unpaid => 'Unpaid',
            self::Cancelling => 'Ends at period end',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }

    /**
     * The subscription's plan governs the tenant's entitlements (through SaaS-3) in these states.
     */
    public function grantsPlan(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue, self::Cancelling], true);
    }
}
