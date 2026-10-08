<?php

namespace App\Http\Middleware\Api;

use App\Enums\ApiScope;
use App\Models\AuditLog;
use App\Services\Api\ApiException;
use App\Services\Tenancy\TenantCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-6: the route's scope must be among the credential's. A refusal is audited on the credential
 * at most once a minute per scope (api_authorization_denied).
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $principal = AuthenticateApiCredential::principal($request);
        $required = ApiScope::from($scope);

        if (! $principal->credential->allows($required)) {
            if (Cache::add(TenantCache::key('api:scope-denied-audited:'.$principal->credential->getKey().':'.$required->value), true, 60)) {
                AuditLog::record($principal->credential, 'api_authorization_denied', null, ['key' => $principal->credential->displayKey(), 'reason' => 'scope', 'scope' => $required->value, 'route' => $request->route()?->getName()]);
            }

            throw ApiException::forbidden('insufficient_scope', "This credential lacks the {$required->value} scope.");
        }

        return $next($request);
    }
}
