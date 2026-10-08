<?php

namespace App\Services\Identity;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 8.4: ends a staff user's sessions. Every session carries the user's session epoch
 * (stamped at sign-in); bumping the epoch invalidates every other session whatever the session
 * driver, the database session rows are deleted outright, and the remember-me token is cycled so a
 * "remember me" cookie cannot sign back in. There are no API tokens in this application (no
 * Sanctum/Passport) — the epoch and remember-token cover every bearer credential that exists.
 */
class SessionRevocationService
{
    public const string SESSION_KEY = 'identity.session_epoch';

    /**
     * Invalidates every session of $user except, optionally, the current one (which is re-stamped
     * with the new epoch). Returns the number of stored sessions deleted.
     */
    public function revokeAll(User $user, ?Session $keep = null, string $reason = 'revoked'): int
    {
        $epoch = (int) $user->session_epoch + 1;
        $token = Str::random(60);

        // A query update: no model events, so no noise in the generic audit trail — the explicit
        // sessions_revoked row below is the record.
        User::query()->whereKey($user->getKey())->update(['session_epoch' => $epoch, 'remember_token' => $token]);
        $user->forceFill(['session_epoch' => $epoch, 'remember_token' => $token])->syncOriginalAttributes(['session_epoch', 'remember_token']);

        $deleted = 0;

        if (config('session.driver') === 'database') {
            $deleted = DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($keep !== null, fn ($query) => $query->where('id', '!=', $keep->getId()))
                ->delete();
        }

        if ($keep !== null) {
            $this->stamp($keep, $user);
        }

        AuditLog::record($user, 'sessions_revoked', null, ['reason' => $reason, 'sessions_deleted' => $deleted, 'kept_current' => $keep !== null]);
        Log::info('identity.sessions_revoked', ['user_id' => $user->getKey(), 'reason' => $reason, 'sessions_deleted' => $deleted]);

        return $deleted;
    }

    public function stamp(Session $session, User $user): void
    {
        $session->put(self::SESSION_KEY, (int) $user->session_epoch);
    }

    /**
     * Whether $session was established at the user's current epoch. A session without a stamp
     * (created before Phase 8.4) counts as epoch 0, so it survives only until the first revocation.
     */
    public function isCurrent(Session $session, User $user): bool
    {
        return (int) $session->get(self::SESSION_KEY, 0) === (int) $user->session_epoch;
    }
}
