<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Identity\SessionRevocationService;
use App\Services\Identity\StaffAccessService;
use App\Services\Lifecycle\LifecycleGuard;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-2: the platform's lock on a whole identity (users.disabled_at) — a compromised or abusive
 * account, decided by platform staff (identity:disable / identity:enable), not by any tenant. A
 * disabled identity cannot sign in or act in any tenant; its sessions and remember-me end at once.
 * Its memberships and roles are untouched, so enabling it again restores exactly what each tenant
 * had decided. Audited in the stream of every tenant the person is an active member of.
 */
class PlatformIdentityService
{
    public function __construct(private readonly SessionRevocationService $sessions) {}

    public function disable(User $user, string $reason): void
    {
        $reason = $this->reason($reason);

        if ($user->disabled_at !== null) {
            return;
        }

        TenantContext::current()->runWithoutTenant(function () use ($user, $reason): void {
            LifecycleGuard::allow(fn () => $user->forceFill(['disabled_at' => now(), 'disabled_reason' => $reason])->save());
            StaffAccessService::invalidateDecisions();
            $this->sessions->revokeAll($user, reason: 'identity_disabled');

            AuditLog::record($user, 'identity_disabled', null, ['reason' => $reason]);
            Log::warning('platform.identity_disabled', ['user_id' => $user->getKey()]);
        });
    }

    public function enable(User $user, string $reason): void
    {
        $reason = $this->reason($reason);

        if ($user->disabled_at === null) {
            return;
        }

        TenantContext::current()->runWithoutTenant(function () use ($user, $reason): void {
            LifecycleGuard::allow(fn () => $user->forceFill(['disabled_at' => null, 'disabled_reason' => null])->save());
            StaffAccessService::invalidateDecisions();

            AuditLog::record($user, 'identity_enabled', null, ['reason' => $reason]);
            Log::notice('platform.identity_enabled', ['user_id' => $user->getKey()]);
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
