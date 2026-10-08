<?php

namespace App\Services\Platform;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\PlatformOperator;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-2: the platform plane's authorisation — who operates Recruitment Edge itself (platform
 * administrators, support and compliance operators). Granted and revoked by platform staff on the
 * command line (platform:operator), audited in the platform stream, never inside a tenant.
 *
 * The boundary: a platform role is NOT a tenant membership and NOT a tenant role. Nothing in the
 * tenant plane consults it — User::canAccessTenant, permits(), policies and Gate::before ignore it —
 * so holding one opens no tenant and shows no tenant data. "All tenants" is never a context
 * (tenant_id NULL never means everyone). Reaching a tenant for support requires that tenant's own
 * time-bound SupportAccessGrant and the SaaS-5 support console that will honour it.
 */
class PlatformAccess
{
    public function holds(User $user, PlatformRole $role): bool
    {
        return PlatformOperator::query()->where('user_id', $user->getKey())->where('role', $role->value)->whereNull('revoked_at')->exists();
    }

    /**
     * @return Collection<int, PlatformRole>
     */
    public function rolesOf(User $user): Collection
    {
        return PlatformOperator::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->pluck('role');
    }

    public function grant(User $user, PlatformRole $role, string $reason): PlatformOperator
    {
        $reason = $this->reason($reason);

        return TenantContext::current()->runWithoutTenant(fn (): PlatformOperator => DB::transaction(function () use ($user, $role, $reason): PlatformOperator {
            /** @var PlatformOperator|null $operator */
            $operator = PlatformOperator::query()->where('user_id', $user->getKey())->where('role', $role->value)->lockForUpdate()->first();

            if ($operator?->isActive()) {
                return $operator;
            }

            $operator ??= new PlatformOperator(['user_id' => $user->getKey(), 'role' => $role]);
            $operator->forceFill(['reason' => $reason, 'granted_at' => now(), 'revoked_at' => null, 'revoked_reason' => null])->save();

            AuditLog::record($operator, 'platform_role_granted', null, ['user_id' => $user->getKey(), 'role' => $role->value, 'reason' => $reason]);
            Log::notice('platform.role_granted', ['user_id' => $user->getKey(), 'role' => $role->value]);

            return $operator;
        }));
    }

    public function revoke(User $user, PlatformRole $role, string $reason): void
    {
        $reason = $this->reason($reason);

        TenantContext::current()->runWithoutTenant(function () use ($user, $role, $reason): void {
            $operator = PlatformOperator::query()->where('user_id', $user->getKey())->where('role', $role->value)->whereNull('revoked_at')->first();

            if ($operator === null) {
                throw new DomainException('This identity does not hold that platform role.');
            }

            $operator->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();

            AuditLog::record($operator, 'platform_role_revoked', ['role' => $role->value], ['reason' => $reason]);
            Log::notice('platform.role_revoked', ['user_id' => $user->getKey(), 'role' => $role->value]);
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required.');
        }

        return mb_substr($reason, 0, 255);
    }
}
