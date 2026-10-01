<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\LoginRequest;
use App\Models\AuditLog;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Candidate portal sign-in on the separate `candidate` guard. Failed attempts are rate-limited per
 * email + IP, and the error never says whether the email has an account.
 */
class AuthController extends Controller
{
    public const int MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('portal.auth.login');
    }

    public function store(LoginRequest $request, CandidatePortalService $portal): RedirectResponse
    {
        $key = 'portal-login:'.Str::lower($request->string('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->recordFailure($request, 'throttled');

            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $credentials = [
            'email' => Str::lower($request->string('email')),
            'password' => $request->string('password')->toString(),
            'is_active' => true,
            fn ($query) => $query->whereNotNull('password'),
        ];

        if (! Auth::guard('candidate')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            $this->recordFailure($request, 'invalid_credentials');

            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        /** @var CandidatePortalAccount $account */
        $account = Auth::guard('candidate')->user();
        $portal->recordLogin($account);

        return redirect()->intended(route('portal.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var CandidatePortalAccount|null $account */
        $account = Auth::guard('candidate')->user();

        if ($account !== null) {
            AuditLog::asCandidate($account, fn () => AuditLog::record($account, 'portal_logout', null, ['outcome' => 'signed_out']));
        }

        Auth::guard('candidate')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    /**
     * Phase 8.8 (D8.8-001): a failed sign-in is audited against the account it named, if that
     * account exists — as an unauthenticated (system) attempt, with the outcome only: never the
     * email, password or anything typed. Attempts on unknown emails are not recorded against
     * anyone, and the response is the same either way.
     */
    private function recordFailure(Request $request, string $reason): void
    {
        $account = CandidatePortalAccount::query()->where('email', Str::lower($request->string('email')))->first();

        if ($account !== null) {
            AuditLog::record($account, 'portal_login_failed', null, ['outcome' => 'failed', 'reason' => $reason]);
        }
    }
}
