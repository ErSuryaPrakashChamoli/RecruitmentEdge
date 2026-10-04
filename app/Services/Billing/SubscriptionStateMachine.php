<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\BillingSubscription;
use App\Services\Platform\Commercial\CommercialSubscriptionService;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-4: the only writer of a subscription's status. Every change follows the transition table,
 * is made on a row locked by the caller (inside its transaction, after the tenant lock), records
 * when and why, is audited old → new in the tenant's stream, bumps the tenant's billing state
 * version (the billing cache key) and is handed to CommercialSubscriptionService, which carries
 * it into SaaS-3. Raw provider states never reach here: adapters map them to canonical events.
 */
class SubscriptionStateMachine
{
    /**
     * @var array<string, list<string>> from => allowed targets
     */
    public const TRANSITIONS = [
        'pending' => ['trialing', 'active', 'cancelled', 'expired'],
        'trialing' => ['active', 'past_due', 'cancelling', 'cancelled', 'expired'],
        'active' => ['past_due', 'cancelling', 'cancelled'],
        'past_due' => ['active', 'unpaid', 'cancelled'],
        'unpaid' => ['active', 'cancelled'],
        'cancelling' => ['active', 'trialing', 'cancelled'],
        'cancelled' => [],
        'expired' => [],
    ];

    public function __construct(
        private readonly CommercialSubscriptionService $commercial,
        private readonly BillingStatusService $status,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  further columns to set with the status
     */
    public function transition(BillingSubscription $subscription, SubscriptionStatus $to, string $reason, string $source, array $changes = []): BillingSubscription
    {
        $from = $subscription->status;

        if ($from === $to) {
            return $subscription;
        }

        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw new DomainException("A subscription cannot move from {$from->label()} to {$to->label()}.");
        }

        $subscription->forceFill([
            'status' => $to,
            'is_live' => $to->isTerminal() ? null : true,
            ...($to->isTerminal() ? ['ended_at' => now()] : []),
            ...$changes,
        ])->save();

        AuditLog::record($subscription, 'subscription_'.$to->value, ['status' => $from->value], [
            'status' => $to->value,
            'source' => $source,
            ...array_map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : ($value instanceof \BackedEnum ? $value->value : $value), $changes),
        ], mb_substr($reason, 0, 255));
        Log::notice('billing.subscription_transition', ['tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id, 'from' => $from->value, 'to' => $to->value, 'source' => $source]);

        $this->status->touch((int) $subscription->tenant_id);
        $this->commercial->sync($subscription);

        return $subscription;
    }

    /**
     * Applies the subscription's current state to SaaS-3 again (idempotent) — after a change that
     * is not a status change (a plan change, a scheduled end).
     */
    public function resync(BillingSubscription $subscription): void
    {
        $this->status->touch((int) $subscription->tenant_id);
        $this->commercial->sync($subscription);
    }
}
