<?php

namespace App\Http\Middleware\Api;

use App\Models\AuditLog;
use App\Services\Api\ApiCredentialAuthenticator;
use App\Services\Api\ApiException;
use App\Services\Api\ApiPrincipal;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-6: authenticates an API request by its bearer credential (ApiCredentialAuthenticator) and
 * makes the credential's owner the request's user (the `api` guard), so the existing policies,
 * permissions and hierarchy scopes decide what it may do — in the credential's tenant, which is now
 * the current tenant. Everything the request audits is attributed to the credential, on behalf of
 * its owner (actor kind `api`).
 *
 * Failed authentications are limited per address (api.rate_limits.auth_failures_per_ip).
 */
class AuthenticateApiCredential implements AuthenticatesRequests
{
    public const string PRINCIPAL = 'api.principal';

    public function __construct(private readonly ApiCredentialAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $failures = 'api-auth-failures:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($failures, (int) config('api.rate_limits.auth_failures_per_ip', 30))) {
            throw new ApiException('rate_limited', 'Too many failed authentications. Retry later.', 429, ['Retry-After' => (string) RateLimiter::availableIn($failures)]);
        }

        $token = (string) $request->bearerToken();

        try {
            if ($token === '') {
                throw ApiException::unauthenticated();
            }

            $principal = $this->authenticator->authenticate($token, $request->ip());
        } catch (ApiException $e) {
            if ($e->status === 401) {
                RateLimiter::hit($failures, 60);
                Log::info('api.authentication_refused', ['code' => $e->errorCode, 'ip' => $request->ip()]);
            }

            throw $e;
        }

        $request->attributes->set(self::PRINCIPAL, $principal);
        $guard = Auth::guard('api');
        $guard->setUser($principal->owner);
        $previous = Auth::getDefaultDriver();
        Auth::shouldUse('api');

        try {
            return AuditLog::asActor('api', (int) $principal->owner->getKey(), fn (): Response => $next($request), $principal->credential);
        } finally {
            // Nothing of this request's identity outlives it (long-lived workers, tests).
            Auth::shouldUse($previous);
            $guard->forgetUser();
        }
    }

    public static function principal(Request $request): ApiPrincipal
    {
        $principal = $request->attributes->get(self::PRINCIPAL);

        if (! $principal instanceof ApiPrincipal) {
            throw ApiException::unauthenticated();
        }

        return $principal;
    }
}
