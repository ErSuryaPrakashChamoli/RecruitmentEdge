<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Identity\SessionRevocationService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: a staff sign-in (password, MFA challenge or remember-me) stamps the session with the
 * user's session epoch, records the last sign-in time and writes the identity audit row.
 */
class RecordStaffSignIn
{
    public function __construct(private readonly SessionRevocationService $sessions) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        $this->sessions->stamp(app('session.store'), $user);

        User::query()->whereKey($user->getKey())->update(['last_login_at' => now()]);

        AuditLog::record($user, 'login', null, ['guard' => $event->guard, 'remember' => $event->remember]);
        Log::info('identity.login', ['user_id' => $user->getKey(), 'guard' => $event->guard, 'remember' => $event->remember]);
    }
}
