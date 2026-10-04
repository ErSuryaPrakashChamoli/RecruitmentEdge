<?php

namespace App\Services\Platform\Commercial;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\BillingSubscription;
use App\Models\Tenant;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-4: the one bridge from billing to the SaaS-3 commercial state. Billing decides what the
 * customer has purchased and paid for (the subscription's canonical state); this translates it —
 * and only it — into SaaS-3 terms through SaaS-3's own services: the plan assignment
 * (PlanAssignmentService), the lifecycle (TenantLifecycleService) and the scheduled end of access.
 * SaaS-3 then decides what the tenant is entitled to; authorisation decides what each person may
 * do. Nothing here touches roles, permissions, memberships, entitlement overrides or tenant data.
 *
 * Runs inside the caller's billing transaction, under the tenant row lock (the commercial lock).
 * Idempotent: applying the same subscription state twice changes nothing.
 *
 * | Subscription        | Plan               | Tenant                                                      |
 * |---------------------|--------------------|-------------------------------------------------------------|
 * | pending             | unchanged          | unchanged                                                   |
 * | trialing            | the purchased plan | unchanged — the SaaS-3 trial stays authoritative            |
 * | active              | the purchased plan | Active (converts a trial; lifts a billing suspension)       |
 * | cancelling          | the purchased plan | Active until cancel_at (scheduled access end)               |
 * | past_due            | the purchased plan | PastDue until the grace period ends (scheduled access end)  |
 * | unpaid              | unchanged          | Suspended (billing_unpaid) — data and memberships kept      |
 * | cancelled / expired | unchanged          | Suspended (subscription_ended), if it ever started          |
 *
 * Billing never lifts a suspension it did not cause (a platform or policy suspension), never acts
 * on a tenant that is closed or still provisioning, and never changes a running SaaS-3 trial.
 */
class CommercialSubscriptionService
{
    /**
     * Suspension reasons billing set, and so may lift: plus an ended trial, which a first payment
     * converts.
     */
    public const LIFTABLE_REASONS = ['billing_unpaid', 'subscription_ended', 'trial_expired'];

    public function __construct(
        private readonly PlanAssignmentService $plans,
        private readonly TenantLifecycleService $lifecycle,
    ) {}

    public function sync(BillingSubscription $subscription, ?User $operator = null): void
    {
        PlatformOperatorGate::assert($operator);

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->whereKey($subscription->tenant_id)->lockForUpdate()->firstOrFail();

        if (in_array($tenant->status, [TenantStatus::Provisioning, TenantStatus::Cancelled, TenantStatus::DeletionPending, TenantStatus::Deleted], true)) {
            $this->notApplied($tenant, $subscription, "The tenant is {$tenant->status->label()}.");

            return;
        }

        try {
            if ($subscription->status->grantsPlan()) {
                $this->assignPlan($tenant, $subscription, $operator);
            }

            match ($subscription->status) {
                SubscriptionStatus::Active => $this->ensureUsable($tenant, $subscription, $operator, null),
                SubscriptionStatus::Cancelling => $this->ensureUsable($tenant, $subscription, $operator, $subscription->cancel_at),
                SubscriptionStatus::PastDue => $this->markPastDue($tenant, $subscription, $operator),
                SubscriptionStatus::Unpaid => $this->suspend($tenant, $subscription, $operator, 'billing_unpaid'),
                // A subscription that never started (never paid, never trialing) ends without effect.
                SubscriptionStatus::Cancelled, SubscriptionStatus::Expired => $subscription->activated_at === null ? null : $this->suspend($tenant, $subscription, $operator, 'subscription_ended'),
                SubscriptionStatus::Pending, SubscriptionStatus::Trialing => null,
            };
        } catch (DomainException $e) {
            // A SaaS-3 rule refused the change (e.g. a retired plan version): billing keeps its own
            // record; the difference is audited for the platform to resolve.
            $this->notApplied($tenant->fresh(), $subscription, $e->getMessage());
        }
    }

    private function assignPlan(Tenant $tenant, BillingSubscription $subscription, ?User $operator): void
    {
        $current = TenantContext::current()->run($tenant, fn (): ?int => TenantPlanAssignment::query()->where('is_current', true)->value('plan_version_id'));

        if ($current === (int) $subscription->plan_version_id) {
            return;
        }

        $this->plans->assign($tenant->fresh(), $subscription->planVersion()->firstOrFail(), 'billing', "Subscription #{$subscription->id} ({$subscription->status->value})", $operator, ['billing_subscription_id' => $subscription->id]);
    }

    private function ensureUsable(Tenant $tenant, BillingSubscription $subscription, ?User $operator, mixed $accessEndsAt): void
    {
        $tenant = $tenant->fresh();

        // A running SaaS-3 trial governs itself; cancelling during it changes nothing here.
        if ($tenant->status === TenantStatus::Trial && ! $tenant->trialHasExpired() && $subscription->status === SubscriptionStatus::Cancelling) {
            return;
        }

        $liftable = $tenant->status === TenantStatus::Suspended && in_array($tenant->status_reason, self::LIFTABLE_REASONS, true);

        if ($tenant->status === TenantStatus::Suspended && ! $liftable) {
            $this->notApplied($tenant, $subscription, "The tenant is suspended ({$tenant->status_reason}); billing does not lift it.");

            return;
        }

        if ($tenant->status !== TenantStatus::Active || $tenant->accessHasEnded()) {
            $tenant = $tenant->status === TenantStatus::Active
                ? $this->lifecycle->setAccessEnd($tenant, null, "Subscription #{$subscription->id} paid", $operator)
                : $this->lifecycle->activate($tenant, "Subscription #{$subscription->id} {$subscription->status->value}", $operator, 'billing');
        }

        $this->lifecycle->setAccessEnd($tenant, $accessEndsAt, $accessEndsAt === null ? "Subscription #{$subscription->id} active" : "Subscription #{$subscription->id} ends at the end of the paid period", $operator);
    }

    private function markPastDue(Tenant $tenant, BillingSubscription $subscription, ?User $operator): void
    {
        $tenant = $tenant->fresh();

        // Only a paying tenant becomes past due; a tenant whose trial ended stays as the trial left it.
        if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::PastDue], true)) {
            return;
        }

        if ($tenant->status === TenantStatus::Active) {
            $tenant = $this->lifecycle->markPastDue($tenant, "Subscription #{$subscription->id}: payment failed", $operator, 'billing');
        }

        $this->lifecycle->setAccessEnd($tenant, $subscription->grace_ends_at, "Subscription #{$subscription->id}: grace period", $operator);
    }

    private function suspend(Tenant $tenant, BillingSubscription $subscription, ?User $operator, string $statusReason): void
    {
        $tenant = $tenant->fresh();

        if (! in_array($tenant->status, [TenantStatus::Trial, TenantStatus::Active, TenantStatus::PastDue], true)) {
            return;
        }

        $this->lifecycle->suspend($tenant, "Subscription #{$subscription->id} {$subscription->status->value}", $operator, 'billing', $statusReason);
    }

    private function notApplied(Tenant $tenant, BillingSubscription $subscription, string $why): void
    {
        TenantContext::current()->run($tenant, fn () => AuditLog::record($subscription, 'billing_commercial_state_not_applied', null, ['subscription_status' => $subscription->status->value, 'tenant_status' => $tenant->status->value], mb_substr($why, 0, 255)));
        Log::warning('platform.billing_commercial_state_not_applied', ['tenant_id' => $tenant->getKey(), 'subscription_id' => $subscription->getKey(), 'subscription_status' => $subscription->status->value]);
    }
}
