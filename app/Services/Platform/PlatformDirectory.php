<?php

namespace App\Services\Platform;

use App\Enums\AccessState;
use App\Enums\AutomationRuleStatus;
use App\Enums\BillingInterval;
use App\Enums\ConnectionStatus;
use App\Enums\DeletionRequestStatus;
use App\Enums\Entitlement;
use App\Enums\EntitlementType;
use App\Enums\InvitationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobPostingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanStatus;
use App\Enums\PlanVersionStatus;
use App\Enums\PlatformEventSeverity;
use App\Enums\RequisitionStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SupportGrantStatus;
use App\Enums\TemplateStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPrice;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\PlatformEvent;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-5: the platform panel's reads across tenants — one reviewed place. Operational metadata
 * only (status, plan, counts, platform audit); never a tenant's business records. Those are
 * reached through a support grant (SupportWorkspace), one tenant and one scope at a time.
 */
class PlatformDirectory
{
    /**
     * Actions a platform operator, the console or the platform's own processes take on a tenant —
     * the platform audit view lists these from every tenant's stream, and the platform stream.
     */
    public const array PLATFORM_ACTIONS = [
        'tenant_activated', 'tenant_suspended', 'tenant_past_due', 'tenant_cancelled', 'tenant_deletion_pending', 'tenant_deleted', 'tenant_deletion_withdrawn',
        'trial_started', 'trial_extended', 'trial_expired', 'plan_assigned', 'entitlement_override_set', 'entitlement_override_removed',
        'support_access_requested', 'support_access_approved', 'support_access_denied', 'support_access_granted', 'support_access_revoked', 'support_access_expired', 'support_access_request_lapsed', 'support_access_used',
        'ownership_assigned', 'ownership_transferred',
        'tenant_deletion_requested', 'tenant_deletion_approved', 'tenant_deletion_cancelled', 'tenant_purge_requested', 'tenant_purge_started', 'tenant_purge_resumed', 'tenant_purge_completed', 'tenant_purge_failed',
        'compliance_export_requested', 'compliance_export_ready', 'compliance_export_downloaded', 'compliance_export_expired',
        // Platform commercial UI: the commercial control plane and billing, whether changed from the
        // panel, the console or billing's own processes. Tenant-side billing details and high-volume
        // provider traffic (webhooks, payment attempts) stay the tenant's.
        'tenant_provisioned', 'tenant_provisioning_started', 'tenant_provisioning_failed', 'tenant_provisioning_retried',
        'plan_changed', 'entitlement_override_created', 'entitlement_override_changed',
        'subscription_created', 'subscription_plan_changed', 'subscription_pending', 'subscription_trialing', 'subscription_active', 'subscription_past_due', 'subscription_unpaid', 'subscription_cancelling', 'subscription_cancelled', 'subscription_expired',
        'invoice_issued', 'invoice_paid', 'invoice_voided',
        'payment_recorded_manually', 'payment_succeeded', 'payment_failed', 'payment_refund_requested', 'payment_refunded',
        'billing_overpayment', 'billing_payment_after_end', 'billing_outcome_refused', 'billing_commercial_state_not_applied',
    ];

    /**
     * Every tenant with its plan, active members and open platform work — one query.
     *
     * @return Builder<Tenant>
     */
    public function tenants(): Builder
    {
        return Tenant::query()
            ->select('tenants.*')
            ->addSelect([
                'plan_name' => $this->currentPlan('plans.name'),
                'plan_code' => $this->currentPlan('plans.code'),
                'plan_version' => $this->currentPlan('plan_versions.version'),
                'active_members' => DB::table('tenant_memberships')->selectRaw('count(*)')->whereColumn('tenant_memberships.tenant_id', 'tenants.id')->where('status', AccessState::Active->value),
                'deletion_status' => DB::table('tenant_deletion_requests')->whereColumn('tenant_deletion_requests.tenant_id', 'tenants.id')->where('is_open', true)->limit(1)->select('status'),
                'active_support_grants' => DB::table('support_access_grants')->selectRaw('count(*)')->whereColumn('support_access_grants.tenant_id', 'tenants.id')->where('status', SupportGrantStatus::Active->value)->where('expires_at', '>', now()),
                'owner_name' => DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->whereColumn('tenant_memberships.tenant_id', 'tenants.id')->where('tenant_memberships.is_owner', true)->limit(1)->select('users.name'),
                'subscription_status' => DB::table('billing_subscriptions')->whereColumn('billing_subscriptions.tenant_id', 'tenants.id')->where('billing_subscriptions.is_live', true)->limit(1)->select('status'),
                'open_invoices' => DB::table('billing_invoices')->selectRaw('count(*)')->whereColumn('billing_invoices.tenant_id', 'tenants.id')->where('billing_invoices.status', InvoiceStatus::Open->value),
            ]);
    }

    /**
     * Subscription states in which billing has decided the tenant is behind on payment.
     *
     * @return list<string>
     */
    private static function behindStatuses(): array
    {
        return [SubscriptionStatus::PastDue->value, SubscriptionStatus::Unpaid->value];
    }

    /**
     * The billing state the tenant list shows, from what tenants() selects: billing's own verdict
     * first (the live subscription past due or unpaid), then open invoices. An invoice is due when
     * issued, so its due date alone says nothing about being behind — billing's grace does.
     */
    public static function billingState(Tenant $row): string
    {
        return match (true) {
            in_array($row->getAttribute('subscription_status'), self::behindStatuses(), true) => 'past_due',
            (int) $row->getAttribute('open_invoices') > 0 => 'invoice_due',
            $row->getAttribute('subscription_status') === null => 'no_subscription',
            default => 'up_to_date',
        };
    }

    /**
     * @return array<string, string> billing state => label
     */
    public static function billingStates(): array
    {
        return ['past_due' => 'Past due', 'invoice_due' => 'Invoice open', 'up_to_date' => 'Up to date', 'no_subscription' => 'No subscription'];
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function whereBillingState(Builder $query, string $state): Builder
    {
        $open = DB::table('billing_invoices')->whereColumn('billing_invoices.tenant_id', 'tenants.id')->where('billing_invoices.status', InvoiceStatus::Open->value);
        $live = fn (bool $behind) => DB::table('billing_subscriptions')->whereColumn('billing_subscriptions.tenant_id', 'tenants.id')->where('billing_subscriptions.is_live', true)->when($behind, fn ($subscriptions) => $subscriptions->whereIn('billing_subscriptions.status', self::behindStatuses()));

        return match ($state) {
            'past_due' => $query->whereExists($live(true)),
            'invoice_due' => $query->whereExists($open)->whereNotExists($live(true)),
            'no_subscription' => $query->whereNotExists($open)->whereNotExists($live(false)),
            'up_to_date' => $query->whereNotExists($open)->whereExists($live(false))->whereNotExists($live(true)),
            default => $query,
        };
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function wherePlan(Builder $query, string $planCode): Builder
    {
        return $query->whereExists(DB::table('tenant_plan_assignments')
            ->join('plan_versions', 'plan_versions.id', '=', 'tenant_plan_assignments.plan_version_id')
            ->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->whereColumn('tenant_plan_assignments.tenant_id', 'tenants.id')
            ->where('tenant_plan_assignments.is_current', true)
            ->where('plans.code', $planCode));
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function whereSubscriptionStatus(Builder $query, string $status): Builder
    {
        return $query->whereExists(DB::table('billing_subscriptions')->whereColumn('billing_subscriptions.tenant_id', 'tenants.id')->where('billing_subscriptions.is_live', true)->where('billing_subscriptions.status', $status));
    }

    private function currentPlan(string $column): QueryBuilder
    {
        return DB::table('tenant_plan_assignments')
            ->join('plan_versions', 'plan_versions.id', '=', 'tenant_plan_assignments.plan_version_id')
            ->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->whereColumn('tenant_plan_assignments.tenant_id', 'tenants.id')
            ->where('tenant_plan_assignments.is_current', true)
            ->limit(1)
            ->select($column);
    }

    /**
     * Support grants in force, and requests waiting for their tenant.
     *
     * @return array{active: int, requested: int}
     */
    public function supportCounts(): array
    {
        return [
            'active' => SupportAccessGrant::query()->withoutTenancy()->where('status', SupportGrantStatus::Active->value)->where('expires_at', '>', now())->count(),
            'requested' => SupportAccessGrant::query()->withoutTenancy()->where('status', SupportGrantStatus::Requested->value)->count(),
        ];
    }

    /**
     * One tenant's operational summary for the tenant detail page.
     *
     * @return array{owner: ?User, plan: ?string, active_members: int, invited_members: int, failed_jobs: int, open_deletion: ?DeletionRequestStatus, usage: list<array{label: string, used: int, allowed: string, over: bool}>}
     */
    public function summary(Tenant $tenant): array
    {
        /** @var Tenant $row */
        $row = $this->tenants()->whereKey($tenant->getKey())->firstOrFail();

        $usage = TenantContext::current()->run($tenant, function (): array {
            $lines = [];

            foreach (app(EntitlementService::class)->overview() as $line) {
                if ($line['entitlement']->type() !== EntitlementType::Limit) {
                    continue;
                }

                $effective = $line['effective'];
                $lines[] = [
                    'label' => $line['entitlement']->label(),
                    'used' => (int) $line['usage'],
                    'allowed' => $effective->unlimited ? 'Unlimited' : ($effective->enabled ? (string) $effective->limit : 'Not included'),
                    'over' => $effective->enabled && ! $effective->unlimited && (int) $line['usage'] > (int) $effective->limit,
                ];
            }

            return $lines;
        });

        return [
            'owner' => app(TenantOwnershipService::class)->owner($tenant),
            'plan' => $row->getAttribute('plan_name'),
            'active_members' => (int) $row->getAttribute('active_members'),
            'invited_members' => (int) DB::table('tenant_invitations')->where('tenant_id', $tenant->getKey())->where('status', InvitationStatus::Pending->value)->where('expires_at', '>', now())->count(),
            'failed_jobs' => $this->failedJobs($tenant),
            'open_deletion' => DeletionRequestStatus::tryFrom((string) $row->getAttribute('deletion_status')),
            'usage' => $usage,
        ];
    }

    /**
     * Members who may receive ownership: active members of the tenant (name and email only).
     *
     * @return array<int, string> user id => label
     */
    public function memberOptions(Tenant $tenant): array
    {
        return User::query()
            ->join('tenant_memberships', 'tenant_memberships.user_id', '=', 'users.id')
            ->where('tenant_memberships.tenant_id', $tenant->getKey())
            ->where('tenant_memberships.status', AccessState::Active->value)
            ->orderBy('users.name')
            ->limit(500)
            ->get(['users.id', 'users.name', 'users.email'])
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    /**
     * The tenant's failed background jobs (their payloads name the tenant).
     */
    public function failedJobs(Tenant $tenant): int
    {
        $id = (int) $tenant->getKey();

        return DB::table('failed_jobs')->where(fn ($query) => $query->where('payload', 'like', '%"tenant_id":'.$id.',%')->orWhere('payload', 'like', '%"tenant_id":'.$id.'}%'))->count();
    }

    /**
     * The platform audit: the platform's own stream, and every tenant's entries of platform actions.
     *
     * @return Builder<AuditLog>
     */
    public function audit(): Builder
    {
        return AuditLog::query()->withoutTenancy()
            // Indexed (tenant_id; action, created_at). Not by actor kind: console-run tenant tasks
            // write business entries, which are the tenant's, not the platform's.
            ->where(fn (Builder $query) => $query->whereNull('tenant_id')->orWhereIn('action', self::PLATFORM_ACTIONS))
            ->with('user:id,name,email');
    }

    /**
     * Support grants across tenants: the operator's own, or all for a platform administrator.
     *
     * @return Builder<SupportAccessGrant>
     */
    public function supportGrants(?int $operatorUserId): Builder
    {
        return SupportAccessGrant::query()->withoutTenancy()
            ->with(['tenant:id,name,slug,status', 'operator.user:id,name,email'])
            ->when($operatorUserId !== null, fn (Builder $query) => $query->whereHas('operator', fn (Builder $operator) => $operator->where('user_id', $operatorUserId)));
    }

    /**
     * The control plane's commercial picture for the overview: counts and amounts that need an
     * operator, each from one grouped query. Amounts stay in their own currencies.
     *
     * @return array{tenants: int, by_status: array<string, int>, plans: array<string, int>, subscriptions: array<string, int>, open_invoices: int, outstanding: list<string>, failed_payments: int, provisioning_errors: int}
     */
    public function commercialOverview(): array
    {
        $byStatus = Tenant::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all();
        $openInvoices = BillingInvoice::query()->withoutTenancy()->where('status', InvoiceStatus::Open->value);

        return [
            'tenants' => array_sum($byStatus),
            'by_status' => $byStatus,
            'plans' => DB::table('tenant_plan_assignments')
                ->join('plan_versions', 'plan_versions.id', '=', 'tenant_plan_assignments.plan_version_id')
                ->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
                ->where('tenant_plan_assignments.is_current', true)
                ->groupBy('plans.name')
                ->orderBy('plans.name')
                ->selectRaw('plans.name as plan, count(distinct tenant_plan_assignments.tenant_id) as tenants')
                ->pluck('tenants', 'plan')->map(fn ($total): int => (int) $total)->all(),
            'subscriptions' => BillingSubscription::query()->withoutTenancy()->where('is_live', true)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all(),
            'open_invoices' => (clone $openInvoices)->count(),
            'outstanding' => (clone $openInvoices)->selectRaw('currency, sum(amount_due_minor) as due')->groupBy('currency')->orderBy('currency')->get()
                ->map(fn (BillingInvoice $row): string => Money::of((int) $row->getAttribute('due'), (string) $row->currency)->format())->all(),
            'failed_payments' => BillingPayment::query()->withoutTenancy()->where('status', PaymentStatus::Failed->value)->where('attempted_at', '>=', now()->subDays(30))->count(),
            'provisioning_errors' => Tenant::query()->where('status', TenantStatus::Provisioning->value)->whereNotNull('provisioning_error')->count(),
        ];
    }

    /**
     * Every plan version with its plan, entitlements, how many tenants are on it and how many
     * current prices it has. Read-only: versions are published through the plan catalog service.
     *
     * @return Builder<PlanVersion>
     */
    public function planCatalog(): Builder
    {
        return PlanVersion::query()
            ->select('plan_versions.*')
            ->addSelect([
                'tenants_count' => DB::table('tenant_plan_assignments')->selectRaw('count(distinct tenant_plan_assignments.tenant_id)')->whereColumn('tenant_plan_assignments.plan_version_id', 'plan_versions.id')->where('tenant_plan_assignments.is_current', true),
                'current_prices' => DB::table('billing_prices')->selectRaw('count(*)')->whereColumn('billing_prices.plan_version_id', 'plan_versions.id')->where('billing_prices.is_current', true),
            ])
            ->with(['plan:id,code,name,description,status', 'entitlements']);
    }

    /**
     * One plan version's prices, current first, then the retired ones (newest first).
     *
     * @return Collection<int, BillingPrice>
     */
    public function prices(int $planVersionId): Collection
    {
        return BillingPrice::query()->where('plan_version_id', $planVersionId)->orderByDesc('is_current')->orderByDesc('effective_from')->get();
    }

    /**
     * What a plan version grants, by registry key, in words ("Included", "25", "Unlimited").
     *
     * @return array<string, string> entitlement label => value
     */
    public function grants(PlanVersion $version): array
    {
        $rows = $version->entitlements->keyBy('key');

        return collect(Entitlement::cases())->mapWithKeys(function (Entitlement $entitlement) use ($rows): array {
            $row = $rows->get($entitlement->value);

            return [$entitlement->label() => $row === null ? 'Not included' : self::describe($entitlement, (bool) $row->enabled, $row->limit_value === null ? null : (int) $row->limit_value, (bool) $row->is_unlimited)];
        })->all();
    }

    /**
     * Plans a tenant may be provisioned on or moved to: active plans, each at its highest published
     * version (the version PlanAssignmentService::latestVersion() assigns).
     *
     * @return array<string, array{label: string, grants: array<string, string>}> plan code => details
     */
    public function offeredPlans(): array
    {
        return Plan::query()
            ->where('status', PlanStatus::Active->value)
            ->orderBy('name')
            ->with(['versions' => fn ($versions) => $versions->where('status', PlanVersionStatus::Published->value)->orderByDesc('version')->with('entitlements')])
            ->get()
            ->filter(fn (Plan $plan): bool => $plan->versions->isNotEmpty())
            ->mapWithKeys(fn (Plan $plan): array => [$plan->code => ['label' => "{$plan->name} (v{$plan->versions->first()->version})", 'grants' => $this->grants($plan->versions->first())]])
            ->all();
    }

    /**
     * @return array<string, string> plan code => name, every plan (for filters)
     */
    public function planOptions(): array
    {
        return Plan::query()->orderBy('name')->pluck('name', 'code')->all();
    }

    /**
     * Current prices of purchasable plans, for a subscription or a plan change — optionally only in
     * one currency and interval (a plan change keeps both).
     *
     * @return array<int, string> price id => "{plan} v{n} · {currency} {amount} / {interval}"
     */
    public function priceOptions(?string $currency = null, ?BillingInterval $interval = null): array
    {
        return BillingPrice::query()
            ->where('is_current', true)
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->when($interval !== null, fn ($query) => $query->where('interval', $interval->value))
            ->whereHas('planVersion', fn ($version) => $version->where('status', PlanVersionStatus::Published->value)->whereHas('plan', fn ($plan) => $plan->where('status', PlanStatus::Active->value)))
            ->with('planVersion.plan')
            ->orderBy('plan_version_id')->orderBy('currency')->orderBy('interval')
            ->get()
            ->mapWithKeys(fn (BillingPrice $price): array => [$price->id => "{$price->planVersion->label()} · {$price->money()->format()} / {$price->interval->label()}"])
            ->all();
    }

    /**
     * The tenant's current plan assignment, in words.
     *
     * @return array{plan: string, code: string, version: int, since: ?string, source: ?string, reason: ?string, by: ?string}|null
     */
    public function currentAssignment(Tenant $tenant): ?array
    {
        $row = DB::table('tenant_plan_assignments')
            ->join('plan_versions', 'plan_versions.id', '=', 'tenant_plan_assignments.plan_version_id')
            ->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->leftJoin('users', 'users.id', '=', 'tenant_plan_assignments.assigned_by')
            ->where('tenant_plan_assignments.tenant_id', $tenant->getKey())
            ->where('tenant_plan_assignments.is_current', true)
            ->first(['plans.name as plan', 'plans.code', 'plan_versions.version', 'tenant_plan_assignments.effective_from', 'tenant_plan_assignments.source', 'tenant_plan_assignments.reason', 'users.name as by']);

        return $row === null ? null : ['plan' => (string) $row->plan, 'code' => (string) $row->code, 'version' => (int) $row->version, 'since' => $row->effective_from, 'source' => $row->source, 'reason' => $row->reason, 'by' => $row->by];
    }

    /**
     * Each registry entitlement for the tenant: what its plan grants, its current override, and what
     * EntitlementService decides (the only evaluation — nothing is recomputed here).
     *
     * @return list<array{entitlement: Entitlement, plan: string, override: ?string, override_reason: ?string, override_ends: ?string, effective: string, source: string, usage: ?int}>
     */
    public function entitlements(Tenant $tenant): array
    {
        $plan = DB::table('tenant_plan_assignments')
            ->join('plan_entitlements', 'plan_entitlements.plan_version_id', '=', 'tenant_plan_assignments.plan_version_id')
            ->where('tenant_plan_assignments.tenant_id', $tenant->getKey())
            ->where('tenant_plan_assignments.is_current', true)
            ->get(['plan_entitlements.key', 'plan_entitlements.enabled', 'plan_entitlements.limit_value', 'plan_entitlements.is_unlimited'])
            ->keyBy('key');
        $overrides = DB::table('tenant_entitlement_overrides')->where('tenant_id', $tenant->getKey())->where('is_current', true)
            ->get(['key', 'enabled', 'limit_value', 'is_unlimited', 'reason', 'ends_at'])
            ->keyBy('key');
        $overview = TenantContext::current()->run($tenant, fn (): array => app(EntitlementService::class)->overview());

        return array_map(function (array $line) use ($plan, $overrides): array {
            /** @var Entitlement $entitlement */
            $entitlement = $line['entitlement'];
            $granted = $plan->get($entitlement->value);
            $override = $overrides->get($entitlement->value);
            $effective = $line['effective'];

            return [
                'entitlement' => $entitlement,
                'plan' => $granted === null ? 'Not included' : self::describe($entitlement, (bool) $granted->enabled, $granted->limit_value === null ? null : (int) $granted->limit_value, (bool) $granted->is_unlimited),
                'override' => $override === null ? null : self::describe($entitlement, (bool) $override->enabled, $override->limit_value === null ? null : (int) $override->limit_value, (bool) $override->is_unlimited),
                'override_reason' => $override?->reason,
                'override_ends' => $override?->ends_at,
                'effective' => self::describe($entitlement, $effective->enabled, $effective->limit, $effective->unlimited),
                'source' => $effective->source,
                'usage' => $line['usage'],
            ];
        }, $overview);
    }

    /**
     * Subscriptions across tenants (live and ended), with their tenant and plan.
     *
     * @return Builder<BillingSubscription>
     */
    public function subscriptions(): Builder
    {
        return BillingSubscription::query()->withoutTenancy()->with(['tenant:id,name,slug', 'planVersion.plan:id,code,name']);
    }

    /**
     * Invoices across tenants: the stored snapshot (totals, currency, dates) — never recomputed.
     *
     * @return Builder<BillingInvoice>
     */
    public function invoices(): Builder
    {
        return BillingInvoice::query()->withoutTenancy()->with(['tenant:id,name,slug', 'subscription' => fn ($subscription) => $subscription->withoutTenancy()->select(['id', 'source'])]);
    }

    /**
     * Payments across tenants, with their invoice number.
     *
     * @return Builder<BillingPayment>
     */
    public function payments(): Builder
    {
        return BillingPayment::query()->withoutTenancy()->with(['tenant:id,name,slug', 'invoice' => fn ($invoice) => $invoice->withoutTenancy()->select(['id', 'number'])]);
    }

    /**
     * @param  Builder<BillingSubscription>  $query
     * @return Builder<BillingSubscription>
     */
    public function whereSubscriptionPlan(Builder $query, string $planCode): Builder
    {
        return $query->whereHas('planVersion.plan', fn ($plan) => $plan->where('code', $planCode));
    }

    /**
     * The tenant's live subscription, if any.
     */
    public function liveSubscription(Tenant $tenant): ?BillingSubscription
    {
        return BillingSubscription::query()->withoutTenancy()->where('tenant_id', $tenant->getKey())->where('is_live', true)->with('planVersion.plan:id,code,name')->first();
    }

    /**
     * The tenant's memberships with their identity's name and email — never credentials.
     *
     * @return Builder<TenantMembership>
     */
    public function members(Tenant $tenant): Builder
    {
        return TenantMembership::query()->where('tenant_id', $tenant->getKey())->with('user:id,name,email');
    }

    /**
     * Role names per member of the tenant, in one query.
     *
     * @return array<int, list<string>> user id => role names
     */
    public function memberRoles(Tenant $tenant): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.tenant_id', $tenant->getKey())
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->orderBy('roles.name')
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->groupBy('model_id')
            ->map(fn (Collection $rows): array => $rows->pluck('name')->all())
            ->all();
    }

    /**
     * Invitations still waiting for an answer — address, name and expiry only.
     *
     * @return list<array{email: string, name: ?string, expires_at: string, invited_at: string}>
     */
    public function pendingInvitations(Tenant $tenant): array
    {
        return DB::table('tenant_invitations')
            ->where('tenant_id', $tenant->getKey())
            ->where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->limit(50)
            ->get(['email', 'name', 'expires_at', 'created_at'])
            ->map(fn (object $row): array => ['email' => (string) $row->email, 'name' => $row->name, 'expires_at' => (string) $row->expires_at, 'invited_at' => (string) $row->created_at])
            ->all();
    }

    /**
     * How far the tenant has set itself up — counts only, never a record.
     *
     * @return array<string, array<string, int>> section => label => count
     */
    public function configuration(Tenant $tenant): array
    {
        $id = (int) $tenant->getKey();
        $live = [RequisitionStatus::Closed->value, RequisitionStatus::Cancelled->value];

        return [
            'Organisation' => [
                'Departments' => DB::table('departments')->where('tenant_id', $id)->count(),
                'Designations' => DB::table('designations')->where('tenant_id', $id)->count(),
                'Locations' => DB::table('locations')->where('tenant_id', $id)->count(),
                'Employees' => DB::table('employees')->where('tenant_id', $id)->whereNull('deleted_at')->count(),
            ],
            'Recruitment' => [
                'Requisitions' => DB::table('recruitment_requisitions')->where('tenant_id', $id)->whereNull('deleted_at')->count(),
                'Active requisitions' => DB::table('recruitment_requisitions')->where('tenant_id', $id)->whereNull('deleted_at')->whereNotIn('status', $live)->count(),
                'Published job postings' => DB::table('job_postings')->where('tenant_id', $id)->where('status', JobPostingStatus::Published->value)->count(),
                'Candidates' => DB::table('candidates')->where('tenant_id', $id)->count(),
                'Applications' => DB::table('candidate_applications')->where('tenant_id', $id)->count(),
            ],
            'Setup' => [
                'Active pipeline stages' => DB::table('recruitment_stages')->where('tenant_id', $id)->where('is_active', true)->count(),
                'Active pipeline templates' => DB::table('recruitment_pipeline_templates')->where('tenant_id', $id)->where('is_active', true)->count(),
                'Active communication templates' => DB::table('communication_templates')->where('tenant_id', $id)->where('status', TemplateStatus::Active->value)->count(),
                'Active offer letter templates' => DB::table('offer_letter_templates')->where('tenant_id', $id)->where('is_active', true)->count(),
                'Active automation rules' => DB::table('automation_rules')->where('tenant_id', $id)->where('status', AutomationRuleStatus::Active->value)->count(),
            ],
            'Integrations' => [
                'Active API credentials' => DB::table('api_credentials')->where('tenant_id', $id)->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
                'Active integration connections' => DB::table('integration_connections')->where('tenant_id', $id)->where('status', ConnectionStatus::Active->value)->count(),
            ],
        ];
    }

    /**
     * The tenant's health at a glance: each indicator with a state the page colours (ok, attention,
     * problem). Built from data the platform already records; nothing is measured anew.
     *
     * @param  array{failed_jobs: int, open_deletion: ?DeletionRequestStatus, usage: list<array{label: string, used: int, allowed: string, over: bool}>}  $summary
     * @return list<array{label: string, value: string, state: string}>
     */
    public function health(Tenant $tenant, array $summary): array
    {
        $status = $tenant->effectiveStatus();
        $subscription = $this->liveSubscription($tenant);
        $events = PlatformEvent::query()->where('tenant_id', $tenant->getKey())->whereNull('acknowledged_at')->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');
        $open = BillingInvoice::query()->withoutTenancy()->where('tenant_id', $tenant->getKey())->where('status', InvoiceStatus::Open->value)->count();
        $over = collect($summary['usage'])->where('over', true)->pluck('label');
        $grants = SupportAccessGrant::query()->withoutTenancy()->where('tenant_id', $tenant->getKey())->where('status', SupportGrantStatus::Active->value)->where('expires_at', '>', now())->count();

        return [
            ['label' => 'Lifecycle', 'value' => $status->label(), 'state' => match ($status) {
                TenantStatus::Active, TenantStatus::Trial => 'ok',
                TenantStatus::PastDue, TenantStatus::Provisioning => 'attention',
                default => 'problem',
            }],
            ['label' => 'Provisioning', 'value' => $tenant->provisioning_error !== null ? 'Failed — can be retried' : ($tenant->provisioned_at !== null ? 'Complete' : 'Not recorded'), 'state' => $tenant->provisioning_error !== null ? 'problem' : 'ok'],
            ['label' => 'Subscription', 'value' => $subscription?->status->label() ?? 'None', 'state' => match (true) {
                $subscription === null => 'attention',
                in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid], true) => 'problem',
                $subscription->status === SubscriptionStatus::Cancelling => 'attention',
                default => 'ok',
            }],
            ['label' => 'Open invoices', 'value' => (string) $open, 'state' => $open > 0 ? 'attention' : 'ok'],
            ['label' => 'Plan limits', 'value' => $over->isEmpty() ? 'Within limits' : 'Over: '.$over->implode(', '), 'state' => $over->isEmpty() ? 'ok' : 'attention'],
            ['label' => 'Failed background jobs', 'value' => (string) $summary['failed_jobs'], 'state' => $summary['failed_jobs'] > 0 ? 'attention' : 'ok'],
            ['label' => 'Unacknowledged events', 'value' => (string) $events->sum(), 'state' => (int) ($events[PlatformEventSeverity::Critical->value] ?? 0) > 0 ? 'problem' : ($events->sum() > 0 ? 'attention' : 'ok')],
            ['label' => 'Support access in force', 'value' => (string) $grants, 'state' => $grants > 0 ? 'attention' : 'ok'],
            ['label' => 'Deletion', 'value' => $summary['open_deletion']?->label() ?? 'None open', 'state' => $summary['open_deletion'] === null ? 'ok' : 'problem'],
        ];
    }

    /**
     * The latest platform actions on the tenant.
     *
     * @return Collection<int, AuditLog>
     */
    public function recentAudit(Tenant $tenant, int $limit = 10): Collection
    {
        return $this->audit()->where('tenant_id', $tenant->getKey())->latest('id')->limit($limit)->get();
    }

    /**
     * The latest platform events about the tenant.
     *
     * @return Collection<int, PlatformEvent>
     */
    public function recentEvents(Tenant $tenant, int $limit = 10): Collection
    {
        return PlatformEvent::query()->where('tenant_id', $tenant->getKey())->latest('id')->limit($limit)->get();
    }

    /**
     * An entitlement value in words: a feature is included or not; a limit is a number or unlimited.
     */
    public static function describe(Entitlement $entitlement, bool $enabled, ?int $limit, bool $unlimited): string
    {
        if ($entitlement->type() === EntitlementType::Feature) {
            return $enabled ? 'Included' : 'Not included';
        }

        return match (true) {
            $unlimited => 'Unlimited',
            $enabled => (string) $limit,
            default => 'Not included',
        };
    }
}
