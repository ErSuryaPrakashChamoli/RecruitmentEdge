<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: who must use MFA, and the audited MFA lifecycle.
 *
 * MFA (an authenticator app, with recovery codes) is required for the roles in
 * identity.mfa.required_roles (by key: CHRO, VP HR, Manager), for anyone holding a permission in
 * identity.mfa.privileged_permissions, and (SaaS-2) for every member of a tenant whose own policy
 * requires it (tenants.mfa_required) — switched on platform-wide by identity.mfa.enforce. The
 * requirement is the identity's: required in one tenant means required everywhere it signs in.
 * A person who must use MFA cannot turn it off themselves; an administrator can reset it (the
 * person then enrols again at their next sign-in).
 */
class MfaService
{
    /**
     * Set while an administrator resets someone's MFA, so the model allows clearing it.
     */
    public static bool $resetting = false;

    public function __construct(
        private readonly AuthorityGuard $authority,
        private readonly SessionRevocationService $sessions,
    ) {}

    public function isEnforced(): bool
    {
        return (bool) config('identity.mfa.enforce');
    }

    /**
     * Whether $user's roles or permissions require MFA (regardless of the enforcement switch).
     */
    public function isRequiredFor(User $user): bool
    {
        // SaaS-2: a tenant may require MFA of every member (its policy, TenantSecurityPolicyService).
        if ($this->requiredByTenantPolicy($user)) {
            return true;
        }

        // SaaS-1: MFA belongs to the identity, so it is required when any tenant the person holds
        // a role in requires it. A member of the current tenant only: that tenant's (eager-loadable)
        // roles are the whole answer.
        if ($user->belongsOnlyToCurrentTenant()) {
            $roleKeys = $user->roles->pluck('key')->filter()->all();

            if (array_intersect($roleKeys, (array) config('identity.mfa.required_roles', [])) !== []) {
                return true;
            }

            return array_intersect($user->getAllPermissions()->pluck('name')->all(), (array) config('identity.mfa.privileged_permissions', [])) !== [];
        }

        // Otherwise read across tenants on purpose: tenant-less pages (profile, MFA set-up) have no
        // team scope, and a team-scoped read there would waive MFA.
        $assignments = DB::table('model_has_roles')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey());

        $roleKeys = (clone $assignments)->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->pluck('roles.key')->filter()->all();

        if (array_intersect($roleKeys, (array) config('identity.mfa.required_roles', [])) !== []) {
            return true;
        }

        $permissions = (clone $assignments)
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->pluck('permissions.name')
            ->merge(DB::table('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->where('model_has_permissions.model_type', $user->getMorphClass())
                ->where('model_has_permissions.model_id', $user->getKey())
                ->pluck('permissions.name'))
            ->all();

        return array_intersect($permissions, (array) config('identity.mfa.privileged_permissions', [])) !== [];
    }

    /**
     * SaaS-2: whether any tenant the person is an Active member of requires MFA of all its members.
     * Identity-wide on purpose, like the role rule: the stricter requirement always wins, so
     * entering or switching to a tenant without the policy can never waive it.
     */
    public function requiredByTenantPolicy(User $user): bool
    {
        if ($user->belongsOnlyToCurrentTenant()) {
            return (bool) TenantContext::current()->tenant()?->mfa_required;
        }

        return DB::table('tenant_memberships')
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
            ->where('tenant_memberships.user_id', $user->getKey())
            ->where('tenant_memberships.status', AccessState::Active->value)
            ->where('tenants.mfa_required', true)
            ->exists();
    }

    public function isEnabledFor(User $user): bool
    {
        return filled($user->getAppAuthenticationSecret());
    }

    /**
     * Called by the User model whenever its MFA secret is saved.
     */
    public function secretChanged(User $user, bool $enabled): void
    {
        if (! $enabled && ! self::$resetting && $this->isEnforced() && $this->isRequiredFor($user)) {
            AuditLog::record($user, 'mfa_disable_refused', null, null);
            Log::warning('identity.mfa_disable_refused', ['user_id' => $user->getKey()]);

            throw new DomainException('Multi-factor authentication is required for your role and cannot be turned off.');
        }

        if (! self::$resetting) {
            AuditLog::record($user, $enabled ? 'mfa_enabled' : 'mfa_disabled', null, ['method' => 'app']);
            Log::info('identity.mfa_'.($enabled ? 'enabled' : 'disabled'), ['user_id' => $user->getKey()]);
        }
    }

    public function recoveryCodesRegenerated(User $user): void
    {
        AuditLog::record($user, 'mfa_recovery_codes_regenerated', null, null);
        Log::info('identity.mfa_recovery_codes_regenerated', ['user_id' => $user->getKey()]);
    }

    /**
     * An administrator clears someone's MFA (lost device). They are signed out everywhere and
     * enrol again at their next sign-in.
     */
    public function resetFor(User $target, User $actor): void
    {
        // SaaS-2: MFA protects the identity in every tenant it belongs to — only an identity that
        // belongs to this tenant alone can have it reset here.
        $this->authority->assertCanManageCredentials($actor, $target, 'users.access.manage', 'You cannot change your own access.');

        self::$resetting = true;

        try {
            $target->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null, 'mfa_enabled_at' => null])->save();
        } finally {
            self::$resetting = false;
        }

        $this->sessions->revokeAll($target, reason: 'mfa_reset');
        AuditLog::record($target, 'mfa_reset_by_admin', null, ['by_user_id' => $actor->id]);
        Log::info('identity.mfa_reset_by_admin', ['user_id' => $target->id, 'actor_id' => $actor->id]);
    }
}
