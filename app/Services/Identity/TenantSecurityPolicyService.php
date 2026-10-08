<?php

namespace App\Services\Identity;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-2: a tenant's own security policy — today, whether every member must use MFA
 * (tenants.mfa_required). Changed only by the tenant's access administrators (users.access.manage),
 * with a reason, audited in the tenant's stream.
 *
 * The requirement applies to the identity (MfaService): a person who belongs to a tenant requiring
 * MFA must use it to sign in at all, in every tenant — switching tenants never waives it. A tenant
 * cannot lower what another tenant requires, and the platform switch identity.mfa.enforce still
 * decides whether requirements are enforced at all.
 */
class TenantSecurityPolicyService
{
    public function setMfaRequired(bool $required, User $actor, string $reason): Tenant
    {
        if (! $actor->can('users.access.manage')) {
            throw new DomainException('Changing the MFA policy needs the users.access.manage permission.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to change the MFA policy.');
        }

        $tenant = DB::transaction(function () use ($required, $actor, $reason): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey(TenantContext::current()->requireId())->lockForUpdate()->firstOrFail();
            $before = (bool) $tenant->mfa_required;

            if ($before === $required) {
                return $tenant;
            }

            $tenant->forceFill(['mfa_required' => $required])->save();

            AuditLog::record($tenant, 'mfa_policy_changed', ['mfa_required' => $before], ['mfa_required' => $required, 'reason' => $reason, 'by_user_id' => $actor->id]);
            Log::info('identity.mfa_policy_changed', ['tenant_id' => $tenant->id, 'mfa_required' => $required, 'actor_id' => $actor->id]);

            return $tenant;
        });

        // The context's tenant is re-read, so this request already sees the new policy.
        TenantContext::current()->setTenant($tenant);
        StaffAccessService::invalidateDecisions();

        return $tenant;
    }
}
