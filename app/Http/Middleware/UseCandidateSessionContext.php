<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.8 (D8.8-001) staff / candidate identity boundary. Runs first in the `web` group, before
 * the session starts:
 *
 * - on candidate portal paths (portal/*) the session uses its own cookie and the default guard is
 *   `candidate` — a staff session, staff remember-me cookie or staff permission is never read,
 *   so nothing on a portal request can run as a staff user;
 * - everywhere else the staff session cookie and the `web` guard apply, so a candidate session is
 *   never read by staff pages or the admin panel.
 *
 * The choice is made from the request path on the server, never from anything the browser sends.
 */
class UseCandidateSessionContext
{
    public const string STAFF_GUARD = 'web';

    public const string CANDIDATE_GUARD = 'candidate';

    public function handle(Request $request, Closure $next): Response
    {
        $portal = self::isCandidateRequest($request);

        app('session')->driver()->setName((string) config($portal ? 'session.candidate_cookie' : 'session.cookie'));
        Auth::shouldUse($portal ? self::CANDIDATE_GUARD : self::STAFF_GUARD);

        return $next($request);
    }

    public static function isCandidateRequest(Request $request): bool
    {
        return $request->is('portal', 'portal/*');
    }
}
