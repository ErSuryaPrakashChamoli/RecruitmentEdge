<?php

use App\Enums\Entitlement;
use App\Enums\PlanVersionStatus;
use App\Enums\RequisitionStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\RecruitmentRequisition;
use App\Models\TenantMembership;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Billing\BillingClock;
use App\Services\Billing\SubscriptionService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\LimitReached;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\RequisitionService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: billing decides what was purchased and paid; SaaS-3 decides what that entitles; the
 * person's permissions decide what they may do. The chain is subscription → plan version → SaaS-3
 * plan assignment → effective entitlement → access — and billing never touches roles, permissions,
 * memberships or data.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
});

function bridgeAllows(BillingWorld $world, string $tenant, Entitlement $entitlement): bool
{
    return $world->in($tenant, fn (): bool => app(EntitlementService::class)->allows($entitlement));
}

function bridgeTick(BillingWorld $world, string $tenant): void
{
    $world->in($tenant, fn () => app(BillingClock::class)->tick());
}

test('the whole chain, state by state: paid, overdue, grace ended, recovered, cancelled', function (): void {
    $this->world->subscribe('paying', 'starter');

    // Pending: nothing purchased yet — the tenant keeps what it had (Growth, from SaaS-3).
    expect(bridgeAllows($this->world, 'paying', Entitlement::AiAssistant))->toBeTrue();

    $this->world->pay('paying');

    // Active: the purchased plan (Starter: no AI) governs.
    expect(bridgeAllows($this->world, 'paying', Entitlement::AiAssistant))->toBeFalse()
        ->and(bridgeAllows($this->world, 'paying', Entitlement::ExportsData))->toBeTrue();

    $this->travel(1)->months();
    $this->travel(1)->minutes();
    bridgeTick($this->world, 'paying');
    $this->world->decline('paying');

    // Past due, in grace: still entitled.
    expect($this->world->tenant('paying')->status)->toBe(TenantStatus::PastDue)
        ->and(bridgeAllows($this->world, 'paying', Entitlement::ExportsData))->toBeTrue();

    // Grace ended: nothing, at once.
    $this->travel(8)->days();

    expect(bridgeAllows($this->world, 'paying', Entitlement::ExportsData))->toBeFalse();

    bridgeTick($this->world, 'paying');
    $this->travel(1)->days();
    bridgeTick($this->world, 'paying');
    $this->world->pay('paying');

    // Recovered: entitled again.
    expect(bridgeAllows($this->world, 'paying', Entitlement::ExportsData))->toBeTrue();

    app(SubscriptionService::class)->cancelForPlatform($this->world->subscription('paying'), 'Off-boarding', immediately: true);

    expect(bridgeAllows($this->world, 'paying', Entitlement::ExportsData))->toBeFalse()
        ->and($this->world->tenant('paying')->status_reason)->toBe('subscription_ended');
});

test('a payment never grants a permission, a role or a membership; a suspension revokes none', function (): void {
    $recruiter = $this->world->in('paying', fn () => User::factory()->create()->assignRole('recruiter'));
    $before = [
        DB::table('model_has_roles')->count(),
        DB::table('role_has_permissions')->count(),
        TenantMembership::query()->where('tenant_id', $this->world->tenants['paying']->id)->where('status', 'active')->count(),
    ];

    $this->world->subscribe('paying');
    $this->world->pay('paying');

    expect($this->world->in('paying', fn () => $recruiter->fresh()->can('billing.manage')))->toBeFalse()
        ->and($this->world->in('paying', fn () => $recruiter->fresh()->can('requisitions.approve')))->toBeFalse();

    app(SubscriptionService::class)->cancelForPlatform($this->world->subscription('paying'), 'Left', immediately: true);

    expect([
        DB::table('model_has_roles')->count(),
        DB::table('role_has_permissions')->count(),
        TenantMembership::query()->where('tenant_id', $this->world->tenants['paying']->id)->where('status', 'active')->count(),
    ])->toBe($before);
});

test('a downgrade through billing keeps every record and only stops new ones above the limit', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $this->world->in('paying', fn () => RecruitmentRequisition::factory()->count(12)->create(['status' => RequisitionStatus::Open]));

    app(SubscriptionService::class)->changePlan($this->world->subscription('paying'), $this->world->prices['starter'], 'Downgrade');

    expect($this->world->in('paying', fn () => RecruitmentRequisition::query()->count()))->toBe(12)
        ->and(fn () => $this->world->in('paying', fn () => app(RequisitionService::class)->create($this->world->admins['paying']->fresh(), fn () => RecruitmentRequisition::factory()->create())))->toThrow(LimitReached::class);
});

test('billing never lifts a suspension it did not cause, and never touches a closed tenant', function (): void {
    $this->world->subscribe('paying');
    app(TenantLifecycleService::class)->suspend($this->world->tenant('paying'), 'Policy breach', statusReason: 'policy');

    $this->world->pay('paying');

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active)
        ->and($this->world->tenant('paying')->status)->toBe(TenantStatus::Suspended)
        ->and($this->world->tenant('paying')->status_reason)->toBe('policy')
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'billing_commercial_state_not_applied')->count()))->toBeGreaterThan(0);

    $this->world->subscribe('other');
    app(TenantLifecycleService::class)->cancel($this->world->tenant('other'), 'Closed by the platform');
    $this->world->pay('other');

    expect($this->world->tenant('other')->status)->toBe(TenantStatus::Cancelled);
});

test('the SaaS-3 trial stays authoritative: an extended trial moves the first billing period, never the reverse', function (): void {
    $this->world->subscribe('trialing', 'growth');
    $trialEnd = $this->world->tenant('trialing')->trial_ends_at;

    app(TenantLifecycleService::class)->extendTrial($this->world->tenant('trialing'), 7, 'Evaluation needs a week more');
    bridgeTick($this->world, 'trialing');

    $subscription = $this->world->subscription('trialing');

    expect($subscription->trial_end->equalTo($trialEnd->copy()->addDays(7)))->toBeTrue()
        ->and($subscription->current_period_start->equalTo($trialEnd->copy()->addDays(7)->startOfSecond()))->toBeTrue()
        ->and($this->world->invoices('trialing'))->toHaveCount(0)
        ->and($this->world->tenant('trialing')->trial_ends_at->equalTo($trialEnd->copy()->addDays(7)))->toBeTrue();
});

test('a SaaS-3 rule refusing the purchased plan is recorded, never forced', function (): void {
    $this->world->subscribe('paying', 'starter');
    app(PlanAssignmentService::class)->latestVersion('starter')->update(['status' => PlanVersionStatus::Retired]);

    $this->world->pay('paying');

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active)
        ->and($this->world->in('paying', fn () => TenantPlanAssignment::query()->where('is_current', true)->sole()->planVersion->plan->code))->toBe('growth')
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'billing_commercial_state_not_applied')->exists()))->toBeTrue();
});
