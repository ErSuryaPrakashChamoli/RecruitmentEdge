<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.8 (D8.8-001): a candidate session is valid only for the password it was opened with.
 * Every candidate sign-in (password, set-password link or remember-me) stores a fingerprint of the
 * account's current password in the server-side session; when the password changes, every other
 * session's fingerprint stops matching and the session is ended here on its next request. A
 * session without a fingerprint (opened before this control existed) adopts the current one.
 *
 * Runs before `auth:candidate` and on the self-scheduling routes, so a stale session is neither
 * signed in nor treated as the owner of a booking. A request without a candidate session passes
 * through untouched (signed links keep working as before).
 */
class EnsureCandidateSessionIsCurrent
{
    public const string SESSION_KEY = 'portal.session_fingerprint';

    public function __construct(private readonly CandidatePortalService $portal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard(UseCandidateSessionContext::CANDIDATE_GUARD)->user();

        if ($account === null) {
            return $next($request);
        }

        // Defence in depth: only a candidate portal account can ever be the candidate identity.
        if (! $account instanceof CandidatePortalAccount) {
            Auth::guard(UseCandidateSessionContext::CANDIDATE_GUARD)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $next($request);
        }

        $stored = $request->session()->get(self::SESSION_KEY);
        $current = $this->portal->sessionFingerprint($account);

        // Like Laravel's AuthenticateSession: a session that has no fingerprint yet (opened before
        // this control existed) adopts the current one; only a mismatch ends the session.
        if ($stored === null) {
            $request->session()->put(self::SESSION_KEY, $current);

            return $next($request);
        }

        if (is_string($stored) && hash_equals($current, $stored)) {
            return $next($request);
        }

        AuditLog::asCandidate($account, fn () => AuditLog::record($account, 'portal_session_ended', null, ['reason' => 'password_changed_or_session_outdated']));

        Auth::guard(UseCandidateSessionContext::CANDIDATE_GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', 'Your session ended because your password changed. Please sign in again.');

        return $next($request);
    }
}
