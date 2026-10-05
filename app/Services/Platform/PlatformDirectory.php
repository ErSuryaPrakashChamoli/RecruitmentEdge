<?php

namespace App\Services\Platform;

use App\Enums\AccessState;
use App\Enums\DeletionRequestStatus;
use App\Enums\EntitlementType;
use App\Enums\InvitationStatus;
use App\Enums\SupportGrantStatus;
use App\Models\AuditLog;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
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
                'plan_name' => DB::table('tenant_plan_assignments')
                    ->join('plan_versions', 'plan_versions.id', '=', 'tenant_plan_assignments.plan_version_id')
                    ->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
                    ->whereColumn('tenant_plan_assignments.tenant_id', 'tenants.id')
                    ->where('tenant_plan_assignments.is_current', true)
                    ->limit(1)
                    ->select('plans.name'),
                'active_members' => DB::table('tenant_memberships')->selectRaw('count(*)')->whereColumn('tenant_memberships.tenant_id', 'tenants.id')->where('status', AccessState::Active->value),
                'deletion_status' => DB::table('tenant_deletion_requests')->whereColumn('tenant_deletion_requests.tenant_id', 'tenants.id')->where('is_open', true)->limit(1)->select('status'),
                'active_support_grants' => DB::table('support_access_grants')->selectRaw('count(*)')->whereColumn('support_access_grants.tenant_id', 'tenants.id')->where('status', SupportGrantStatus::Active->value)->where('expires_at', '>', now()),
            ]);
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
}
