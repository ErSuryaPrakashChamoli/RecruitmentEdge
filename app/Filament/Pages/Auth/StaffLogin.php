<?php

namespace App\Filament\Pages\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8.4: Filament's sign-in page (per-IP throttling, timing-safe failures, MFA challenge,
 * access-state check through canAccessPanel) plus a per-account lockout: after too many failed
 * password or MFA attempts against one address from any number of IPs, that account is locked for
 * a while. The lockout is audited.
 */
class StaffLogin extends Login
{
    public const int MAX_FAILURES = 10;

    public const int LOCKOUT_SECONDS = 900;

    public function authenticate(): ?LoginResponse
    {
        $email = strtolower(trim((string) ($this->data['email'] ?? '')));
        $key = 'staff-login:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            event(new Lockout(request()));

            if ($user = User::query()->where('email', $email)->first()) {
                AuditLog::record($user, 'login_locked_out', null, ['retry_after_seconds' => RateLimiter::availableIn($key)]);
            }

            throw ValidationException::withMessages([
                'data.email' => __('Too many failed sign-in attempts for this account. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $e) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw $e;
        }

        if ($response !== null) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
