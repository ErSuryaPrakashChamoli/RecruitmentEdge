<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-4: billing's passage of time for the current tenant — run hourly by `billing:sweep` (a
 * platform task that visits every open tenant). Correctness never waits for it: an ended grace
 * period or paid period already closes access on every request (tenants.access_ends_at); this
 * records it and moves the subscription on.
 *
 * - Trialing: follows the SaaS-3 trial (an extended trial moves the first period); at the trial's
 *   end the first invoice is issued and collected — the trial's own end is SaaS-3's.
 * - Pending: never paid within the grace period → Expired.
 * - Active: at the period's end the next period starts and its invoice is issued (in advance).
 * - Cancelling: at cancel_at → Cancelled.
 * - PastDue: at the grace period's end → Unpaid.
 * - An open invoice of provider billing is collected again every billing.collection_retry_hours.
 */
class BillingClock
{
    private const MAX_PERIODS_PER_TICK = 24;

    public function __construct(
        private readonly SubscriptionStateMachine $machine,
        private readonly InvoiceService $invoices,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @return array{changed: list<string>, collected: int}
     */
    public function tick(): array
    {
        $tenantId = TenantContext::current()->requireId();
        $subscriptionId = BillingSubscription::query()->where('is_live', true)->value('id');

        if ($subscriptionId === null) {
            return ['changed' => [], 'collected' => 0];
        }

        $changed = DB::transaction(function () use ($tenantId, $subscriptionId): array {
            $tenant = BillingLock::tenant($tenantId);
            $subscription = BillingLock::subscription((int) $subscriptionId);
            $changed = [];

            if ($subscription->status === SubscriptionStatus::Trialing) {
                if ($tenant->status === TenantStatus::Trial && $tenant->trial_ends_at !== null && ! $tenant->trial_ends_at->equalTo($subscription->trial_end)) {
                    $start = $tenant->trial_ends_at->copy()->startOfSecond();
                    $subscription->forceFill(['trial_end' => $tenant->trial_ends_at, 'current_period_start' => $start, 'current_period_end' => $subscription->interval->after($start)])->save();
                    AuditLog::record($subscription, 'subscription_trial_synced', null, ['trial_end' => $tenant->trial_ends_at->toIso8601String()]);
                    $changed[] = 'trial synced';
                }

                if (! $subscription->current_period_start->isFuture()) {
                    $this->invoices->issue($subscription, $subscription->current_period_start, $subscription->current_period_end);
                    $changed[] = 'first invoice issued';
                }
            }

            if ($subscription->status === SubscriptionStatus::Pending && $subscription->created_at->copy()->addDays((int) config('billing.grace_days', 7))->isPast()) {
                $this->machine->transition($subscription, SubscriptionStatus::Expired, 'Never paid', 'scheduler');
                $changed[] = 'expired';
            }

            for ($i = 0; $subscription->status === SubscriptionStatus::Active && ! $subscription->current_period_end->isFuture() && $i < self::MAX_PERIODS_PER_TICK; $i++) {
                $start = $subscription->current_period_end->copy();
                $subscription->forceFill(['current_period_start' => $start, 'current_period_end' => $subscription->interval->after($start)])->save();
                $this->invoices->issue($subscription, $start, $subscription->current_period_end);
                $changed[] = 'renewed';
            }

            if ($subscription->status === SubscriptionStatus::Cancelling && $subscription->cancel_at !== null && ! $subscription->cancel_at->isFuture()) {
                $this->machine->transition($subscription, SubscriptionStatus::Cancelled, 'Paid period ended', 'scheduler');
                $changed[] = 'cancelled';
            }

            if ($subscription->status === SubscriptionStatus::PastDue && $subscription->grace_ends_at !== null && ! $subscription->grace_ends_at->isFuture()) {
                $this->machine->transition($subscription, SubscriptionStatus::Unpaid, 'Grace period ended unpaid', 'scheduler');
                $changed[] = 'unpaid';
            }

            return $changed;
        });

        return ['changed' => $changed, 'collected' => $this->collectDue((int) $subscriptionId, $tenantId)];
    }

    /**
     * Collects the subscription's open invoices that have no attempt in flight and no failed attempt
     * within the retry interval.
     */
    private function collectDue(int $subscriptionId, int $tenantId): int
    {
        $subscription = BillingSubscription::query()->find($subscriptionId);

        if ($subscription === null || $subscription->source !== SubscriptionSource::Provider || $subscription->status->isTerminal()) {
            return 0;
        }

        $retryAfter = now()->subHours((int) config('billing.collection_retry_hours', 24));
        $due = BillingInvoice::query()
            ->where('billing_subscription_id', $subscriptionId)
            ->where('status', InvoiceStatus::Open->value)
            ->where('amount_due_minor', '>', 0)
            ->whereDoesntHave('payments', fn ($payments) => $payments->where(fn ($recent) => $recent->where('status', PaymentStatus::Pending->value)->orWhere(fn ($failed) => $failed->where('status', PaymentStatus::Failed->value)->where('attempted_at', '>', $retryAfter))))
            ->orderBy('period_start')
            ->get();

        foreach ($due as $invoice) {
            $this->subscriptions->collectSafely($tenantId, $invoice);
        }

        return $due->count();
    }
}
