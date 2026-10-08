<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.8 (SEC-88-11, engineering default E-03): defensive headers on the pages people outside
 * the staff panel see or type credentials into — the candidate portal, the career site and the
 * staff sign-in / password-reset pages. They cannot be framed by another site, content types are
 * not sniffed, referrers do not leak paths, and HTTPS is pinned once a page is served over it.
 *
 * No full Content-Security-Policy is set (approved scope): only `frame-ancestors`. A header a
 * response already carries is left untouched.
 */
class AddSecurityHeaders
{
    /**
     * @var array<string, string>
     */
    public const array HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public const string HSTS = 'max-age=31536000';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::appliesTo($request)) {
            return $response;
        }

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }

    /**
     * Candidate portal, career site, and the staff panel's authentication pages (sign-in,
     * password reset, multi-factor set-up).
     */
    public static function appliesTo(Request $request): bool
    {
        return $request->is('portal', 'portal/*', 'careers', 'careers/*')
            || $request->routeIs('filament.*.auth.*');
    }
}
