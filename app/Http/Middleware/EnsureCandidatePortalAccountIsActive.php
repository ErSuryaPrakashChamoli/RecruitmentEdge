<?php

namespace App\Http\Middleware;

use App\Models\CandidatePortalAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a candidate whose portal access was revoked after they signed in.
 */
class EnsureCandidatePortalAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var CandidatePortalAccount|null $account */
        $account = Auth::guard('candidate')->user();

        if ($account !== null && ! $account->is_active) {
            Auth::guard('candidate')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login')->withErrors(['email' => 'Your portal access has been turned off. Please contact your recruiter.']);
        }

        return $next($request);
    }
}
