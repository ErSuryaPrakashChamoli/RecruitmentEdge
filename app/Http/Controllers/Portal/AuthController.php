<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\LoginRequest;
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
        Auth::guard('candidate')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
