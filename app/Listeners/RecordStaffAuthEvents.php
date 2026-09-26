<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Identity\SessionRevocationService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: the identity audit for staff authentication. Sign-out, failed sign-in (against a known
 * login; an unknown address is only logged, with no identifier), lockout, and the password-reset
 * flow. A completed reset signs the person out of every other session. Never logs credentials.
 */
class RecordStaffAuthEvents
{
    public function __construct(private readonly SessionRevocationService $sessions) {}

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            AuditLog::record($event->user, 'logout', null, ['guard' => $event->guard]);
            Log::info('identity.logout', ['user_id' => $event->user->getKey()]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        if (! $event->user instanceof User) {
            Log::info('identity.login_failed', ['guard' => $event->guard, 'known_user' => false]);

            return;
        }

        AuditLog::record($event->user, 'login_failed', null, ['guard' => $event->guard, 'access_status' => $event->user->access_status?->value]);
        Log::info('identity.login_failed', ['user_id' => $event->user->getKey(), 'guard' => $event->guard]);
    }

    /**
     * The per-account lockout itself is audited by StaffLogin, which knows the account.
     */
    public function handleLockout(Lockout $event): void
    {
        Log::warning('identity.login_locked_out', ['ip' => $event->request->ip()]);
    }

    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        if ($event->user instanceof User) {
            AuditLog::record($event->user, 'password_reset_requested', null, null);
            Log::info('identity.password_reset_requested', ['user_id' => $event->user->getKey()]);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->sessions->revokeAll($event->user, reason: 'password_reset');
        AuditLog::record($event->user, 'password_reset_completed', null, null);
        Log::info('identity.password_reset_completed', ['user_id' => $event->user->getKey()]);
    }
}
