<?php

namespace App\Services\Platform;

use App\Enums\SupportScope;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingStatusService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-5: what a support operator sees of one tenant through its grant — read-only, one scope at a
 * time, re-checked on every call (SupportAccessService::usableGrant). Nothing here writes to the
 * tenant, signs in as anyone, or reaches a record outside the grant's tenant and scope.
 *
 * - Diagnostics: status, plan and usage, billing status, failed background work (job and count).
 * - People: members — name, email, access state, owner, roles, last sign-in, MFA enrolled.
 * - Audit: the tenant's own audit stream — what, who, when, why (not the changed values).
 */
class SupportWorkspace
{
    public function __construct(
        private readonly SupportAccessService $support,
        private readonly PlatformDirectory $directory,
    ) {}

    /**
     * @return array{tenant: Tenant, summary: array<string, mixed>, billing: array<string, mixed>, failed_work: list<array{job: string, count: int, last_failed_at: string}>}
     */
    public function diagnostics(int $grantId, User $operatorUser): array
    {
        $grant = $this->support->usableGrant($grantId, SupportScope::Diagnostics, $operatorUser);
        $tenant = Tenant::query()->findOrFail($grant->tenant_id);
        $id = (int) $tenant->getKey();

        $failed = DB::table('failed_jobs')
            ->where(fn ($query) => $query->where('payload', 'like', '%"tenant_id":'.$id.',%')->orWhere('payload', 'like', '%"tenant_id":'.$id.'}%'))
            ->orderByDesc('id')->limit(500)->get(['payload', 'failed_at'])
            ->groupBy(fn (object $job): string => (string) (json_decode((string) $job->payload, true)['displayName'] ?? 'unknown'))
            ->map(fn ($jobs, string $job): array => ['job' => $job, 'count' => $jobs->count(), 'last_failed_at' => (string) $jobs->max('failed_at')])
            ->values()->all();

        return [
            'tenant' => $tenant,
            'summary' => $this->directory->summary($tenant),
            'billing' => TenantContext::current()->run($tenant, fn (): array => app(BillingStatusService::class)->summary()),
            'failed_work' => $failed,
        ];
    }

    /**
     * @return Builder<User>
     */
    public function people(int $grantId, User $operatorUser): Builder
    {
        $grant = $this->support->usableGrant($grantId, SupportScope::People, $operatorUser);
        $tenantId = (int) $grant->tenant_id;

        return User::query()
            ->join('tenant_memberships', 'tenant_memberships.user_id', '=', 'users.id')
            ->where('tenant_memberships.tenant_id', $tenantId)
            ->select(['users.id', 'users.name', 'users.email', 'users.last_login_at', 'tenant_memberships.status as membership_status', 'tenant_memberships.is_owner'])
            ->selectRaw('case when users.app_authentication_secret is null then 0 else 1 end as mfa_enrolled')
            ->addSelect([
                'role_names' => DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', (new User)->getMorphClass())
                    ->where('model_has_roles.tenant_id', $tenantId)
                    ->selectRaw('group_concat(roles.name)'),
            ]);
    }

    /**
     * @return Builder<AuditLog>
     */
    public function audit(int $grantId, User $operatorUser): Builder
    {
        $grant = $this->support->usableGrant($grantId, SupportScope::Audit, $operatorUser);

        return AuditLog::query()->withoutTenancy()
            ->where('tenant_id', (int) $grant->tenant_id)
            ->select(['id', 'tenant_id', 'user_id', 'actor_kind', 'auditable_type', 'auditable_id', 'action', 'reason', 'created_at'])
            ->with('user:id,name');
    }
}
