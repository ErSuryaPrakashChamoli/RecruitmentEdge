<?php

namespace App\Services\Api;

use App\Enums\Entitlement;
use App\Models\ApiCredential;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Identity\StaffAccessService;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-6: turns a bearer token into an ApiPrincipal — or refuses, failing closed:
 *
 * 1. The token's shape; its key id finds the credential (the one lookup made before any tenant is
 *    known); the secret's SHA-256 is compared in constant time. Unknown key, wrong secret and bad
 *    shape are one answer (invalid_credential): nothing to enumerate.
 * 2. A revoked or expired credential is refused (credential_inactive).
 * 3. The tenant is the credential's own — never a URL, parameter or payload value. It must exist and
 *    be usable now (SaaS-3/4 lifecycle; tenant_unavailable).
 * 4. The owner must still be allowed in: identity not disabled, an Active membership of this tenant
 *    with a role, and still holding integrations.manage (credential_inactive).
 * 5. The tenant must be entitled to api.access (entitlement_required).
 *
 * The tenant is entered (TenantContext) from step 3 on. Nothing here is cached: revocation, a lost
 * membership, a suspension or a removed entitlement take effect on the very next request.
 */
class ApiCredentialAuthenticator
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly StaffAccessService $access,
    ) {}

    public function authenticate(string $token, ?string $ip = null): ApiPrincipal
    {
        if (preg_match(ApiCredentialService::TOKEN_PATTERN, $token, $parts) !== 1) {
            throw ApiException::invalidCredential();
        }

        // The key id is opaque and globally unique; the tenant is not known yet (reviewed crossing).
        /** @var ApiCredential|null $credential */
        $credential = ApiCredential::query()->withoutTenancy()->where('key_id', $parts[1])->first();
        $expected = $credential?->secret_hash ?? str_repeat('0', 64);

        if (! hash_equals($expected, hash('sha256', $parts[2])) || $credential === null) {
            if ($credential !== null) {
                $this->recordFailure($credential, 'wrong_secret', $ip);
            }

            throw ApiException::invalidCredential();
        }

        if (! $credential->isUsable()) {
            throw ApiException::inactiveCredential();
        }

        $tenant = Tenant::query()->find($credential->tenant_id);

        if ($tenant === null || ! $tenant->isUsable()) {
            throw ApiException::forbidden('tenant_unavailable', 'This organisation\'s account is not active, so its API is unavailable.');
        }

        TenantContext::current()->setTenant($tenant);

        $owner = User::query()->find($credential->user_id);

        if ($owner === null || ! $this->access->identityPermits($owner) || ! $owner->canAccessTenant($tenant) || ! $owner->can('integrations.manage')) {
            throw ApiException::inactiveCredential();
        }

        if (! $this->entitlements->allows(Entitlement::ApiAccess, $tenant)) {
            throw ApiException::forbidden('entitlement_required', 'API access is not included in this organisation\'s plan.');
        }

        $this->touch($credential, $ip);

        return new ApiPrincipal($credential, $owner, $tenant);
    }

    /**
     * A known credential presented with a wrong secret: audited on the credential, at most once a
     * minute per credential (a flood must not flood the audit trail).
     */
    private function recordFailure(ApiCredential $credential, string $reason, ?string $ip): void
    {
        Log::warning('api.authentication_failed', ['key_id' => $credential->key_id, 'tenant_id' => $credential->tenant_id, 'reason' => $reason, 'ip' => $ip]);

        TenantContext::current()->run((int) $credential->tenant_id, function () use ($credential, $reason): void {
            if (Cache::add(TenantCache::key('api:auth-failure-audited:'.$credential->getKey()), true, 60)) {
                AuditLog::record($credential, 'api_authentication_failed', null, ['key' => $credential->displayKey(), 'reason' => $reason]);
            }
        });
    }

    private function touch(ApiCredential $credential, ?string $ip): void
    {
        $resolution = (int) config('api.credentials.last_used_resolution', 60);

        if ($credential->last_used_at === null || $credential->last_used_at->lt(now()->subSeconds($resolution))) {
            ApiCredential::query()->whereKey($credential->getKey())->update(['last_used_at' => now(), 'last_used_ip' => $ip !== null ? mb_substr($ip, 0, 45) : null]);
        }
    }
}
