<?php

namespace App\Services\Identity;

use App\Models\AuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: who must use MFA, and the audited MFA lifecycle.
 *
 * MFA (an authenticator app, with recovery codes) is required for the roles in
 * identity.mfa.required_roles (by key: CHRO, VP HR, Manager) and for anyone holding a permission in
 * identity.mfa.privileged_permissions — one central policy, switched on by identity.mfa.enforce.
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
        $roleKeys = $user->roles->pluck('key')->filter()->all();

        if (array_intersect($roleKeys, (array) config('identity.mfa.required_roles', [])) !== []) {
            return true;
        }

        $permissions = $user->getAllPermissions()->pluck('name')->all();

        return array_intersect($permissions, (array) config('identity.mfa.privileged_permissions', [])) !== [];
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
        $this->authority->assertCanManageAccess($actor, $target);

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
