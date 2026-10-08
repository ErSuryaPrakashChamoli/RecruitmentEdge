<?php

namespace App\Services\Api;

use App\Enums\ApiScope;
use App\Enums\Entitlement;
use App\Models\ApiCredential;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementDenied;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SaaS-6: a tenant's API credentials — issued, rotated and revoked by members holding
 * integrations.manage, in their own tenant, while the tenant is entitled to api.access.
 *
 * - A credential acts as its owner (the member who issued it) and only in this tenant; its scopes
 *   can only narrow what that member may do, and a member can grant only scopes whose permission
 *   they hold. The owner's access is re-checked on every request (ApiCredentialAuthenticator).
 * - The token is `re_<key id>_<secret>`: 20 + 40 random alphanumerics. Only the SHA-256 of the
 *   secret is stored; the token is returned once, by issue() and rotate(), and never again.
 * - Only the owner rotates (the new secret acts as the owner); any credential administrator revokes.
 * - The number of active credentials per tenant is capped under the tenant row lock.
 */
class ApiCredentialService
{
    public const string TOKEN_PATTERN = '/^re_([A-Za-z0-9]{20})_([A-Za-z0-9]{40})$/';

    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @param  list<ApiScope>  $scopes
     * @return array{credential: ApiCredential, token: string}
     */
    public function issue(User $actor, string $name, array $scopes, int $expiryDays): array
    {
        $tenant = $this->assertAdministrator($actor);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainException('Name the credential (up to 80 characters).');
        }

        $values = $this->scopes($actor, $scopes);

        if ($expiryDays < 1 || $expiryDays > (int) config('api.credentials.max_expiry_days', 365)) {
            throw new DomainException('A credential expires within '.config('api.credentials.max_expiry_days', 365).' days.');
        }

        $secret = Str::random(40);

        try {
            $credential = DB::transaction(function () use ($tenant, $actor, $name, $values, $expiryDays, $secret): ApiCredential {
                Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

                $active = ApiCredential::query()->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();

                if ($active >= (int) config('api.credentials.max_active_per_tenant', 25)) {
                    throw new DomainException('This organisation already has the most active API credentials it may hold. Revoke one first.');
                }

                $credential = ApiCredential::query()->create([
                    'user_id' => $actor->getKey(),
                    'name' => $name,
                    'key_id' => Str::random(20),
                    'secret_hash' => hash('sha256', $secret),
                    'scopes' => $values,
                    'expires_at' => now()->addDays($expiryDays),
                    'created_by' => $actor->getKey(),
                ]);

                AuditLog::record($credential, 'api_credential_created', null, ['name' => $name, 'key' => $credential->displayKey(), 'scopes' => $values, 'owner_user_id' => $actor->getKey(), 'expires_at' => $credential->expires_at->toIso8601String()]);

                return $credential;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('A credential with this name already exists.');
        }

        return ['credential' => $credential, 'token' => self::token($credential->key_id, $secret)];
    }

    /**
     * A new secret for the same credential (the old one stops working at once). Owner only.
     *
     * @return array{credential: ApiCredential, token: string}
     */
    public function rotate(ApiCredential $credential, User $actor): array
    {
        $this->assertAdministrator($actor);
        $secret = Str::random(40);

        $rotated = DB::transaction(function () use ($credential, $actor, $secret): ApiCredential {
            $locked = $this->lock($credential);

            if ((int) $locked->user_id !== (int) $actor->getKey()) {
                throw new DomainException('Only the credential\'s owner can rotate it: the new secret acts as its owner.');
            }

            if (! $locked->isUsable()) {
                throw new DomainException('A revoked or expired credential cannot be rotated. Issue a new one.');
            }

            $locked->forceFill(['secret_hash' => hash('sha256', $secret), 'rotated_at' => now()])->save();
            AuditLog::record($locked, 'api_credential_rotated', null, ['key' => $locked->displayKey()]);

            return $locked;
        });

        return ['credential' => $rotated, 'token' => self::token($rotated->key_id, $secret)];
    }

    public function revoke(ApiCredential $credential, User $actor, string $reason): ApiCredential
    {
        // Revoking needs no entitlement: an incident must never wait for the plan. (In a closed tenant no
        // member acts at all, and the API refuses every credential of that tenant.)
        $this->assertAdministrator($actor, forRevocation: true);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to revoke a credential.');
        }

        return DB::transaction(function () use ($credential, $actor, $reason): ApiCredential {
            $locked = $this->lock($credential);

            if ($locked->isRevoked()) {
                throw new DomainException('This credential is already revoked.');
            }

            $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'revoke_reason' => mb_substr($reason, 0, 255)])->save();
            AuditLog::record($locked, 'api_credential_revoked', null, ['key' => $locked->displayKey()], $reason);

            return $locked;
        });
    }

    /**
     * Scopes $actor may grant: known ones, at least one, each backed by a permission they hold.
     *
     * @param  list<ApiScope>  $scopes
     * @return list<string>
     */
    public function scopes(User $actor, array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter($scopes, fn (mixed $scope): bool => $scope instanceof ApiScope), SORT_REGULAR));

        if ($scopes === []) {
            throw new DomainException('Choose at least one scope.');
        }

        foreach ($scopes as $scope) {
            if ($scope->permission() !== null && ! $actor->can($scope->permission())) {
                throw new DomainException("You cannot grant \"{$scope->label()}\": you do not hold that permission yourself.");
            }
        }

        return array_map(fn (ApiScope $scope): string => $scope->value, $scopes);
    }

    /**
     * Scopes $actor could grant (for forms).
     *
     * @return list<ApiScope>
     */
    public function grantableScopes(User $actor): array
    {
        return array_values(array_filter(ApiScope::cases(), fn (ApiScope $scope): bool => $scope->permission() === null || $actor->can($scope->permission())));
    }

    public static function token(string $keyId, string $secret): string
    {
        return 're_'.$keyId.'_'.$secret;
    }

    private function assertAdministrator(User $actor, bool $forRevocation = false): Tenant
    {
        $tenant = TenantContext::current()->requireTenant();

        // No member acts in a closed tenant (SaaS-2) — say why rather than blame the permission.
        if (! $forRevocation && ! $tenant->fresh()->isUsable()) {
            throw new DomainException('API credentials cannot be managed while the organisation is not active.');
        }

        if (! $actor->can('integrations.manage')) {
            throw new DomainException('Managing API credentials needs the integrations.manage permission.');
        }

        if ($forRevocation) {
            return $tenant;
        }

        if (! $this->entitlements->allows(Entitlement::ApiAccess, $tenant->fresh())) {
            throw new EntitlementDenied(Entitlement::ApiAccess, 'not_in_plan');
        }

        return $tenant;
    }

    private function lock(ApiCredential $credential): ApiCredential
    {
        /** @var ApiCredential */
        return ApiCredential::query()->whereKey($credential->getKey())->lockForUpdate()->firstOrFail();
    }
}
