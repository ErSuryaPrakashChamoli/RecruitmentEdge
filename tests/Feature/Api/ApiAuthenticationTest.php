<?php

use App\Enums\AccessState;
use App\Enums\ApiScope;
use App\Enums\Entitlement;
use App\Enums\TenantStatus;
use App\Models\ApiCredential;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\TenantMembership;
use App\Services\Api\ApiCredentialService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\PlatformIdentityService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\ApiWorld;

/*
 * SaaS-6: an API request is authenticated by its credential alone; the credential decides the
 * tenant, and every request re-checks the credential, its owner's access, the tenant's lifecycle
 * and its entitlement — failing closed with one uniform answer for anything unknown.
 */
beforeEach(function (): void {
    $this->world = ApiWorld::build($this->tenant);
});

function apiAuthGet(string $uri, ?string $token): TestResponse
{
    return test()->getJson($uri, $token !== null ? ApiWorld::headers($token) : ['Accept' => 'application/json']);
}

test('a credential identifies its own tenant and owner — nothing in the request can choose another', function (): void {
    apiAuthGet('/api/v1/me?tenant_id='.$this->world->identity->beta->id, $this->world->tokenA)
        ->assertOk()
        ->assertJsonPath('data.organisation.slug', 'acme')
        ->assertJsonPath('data.acting_as.id', $this->world->identity->adminA->id)
        ->assertJsonPath('data.credential.scopes', array_map(fn (ApiScope $scope) => $scope->value, ApiScope::cases()))
        ->assertHeader('X-Request-Id');

    $this->getJson('/api/v1/me', [...ApiWorld::headers($this->world->tokenA), 'X-Tenant' => 'beta'])->assertJsonPath('data.organisation.slug', 'acme');
    apiAuthGet('/api/v1/me', $this->world->tokenB)->assertJsonPath('data.organisation.slug', 'beta');
});

test('a missing, malformed, unknown or wrong credential gets one answer and nothing to enumerate', function (): void {
    $credential = ApiCredential::query()->sole();
    $wrongSecret = 're_'.$credential->key_id.'_'.str_repeat('x', 40);

    apiAuthGet('/api/v1/me', null)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated')->assertHeader('WWW-Authenticate', 'Bearer');

    foreach (['nonsense', 're_'.str_repeat('a', 20).'_'.str_repeat('b', 40), $wrongSecret] as $token) {
        apiAuthGet('/api/v1/me', $token)->assertUnauthorized()->assertExactJson(['error' => ['code' => 'invalid_credential', 'message' => 'The API credential is not valid.', 'request_id' => Context::get('request_id')]]);
    }

    expect(AuditLog::query()->where('action', 'api_authentication_failed')->sole()->changes)->toMatchArray(['reason' => 'wrong_secret'])
        ->and(json_encode(AuditLog::query()->get()))->not->toContain(str_repeat('x', 40));
});

test('a revoked or expired credential stops at once', function (string $ending): void {
    $credential = ApiCredential::query()->sole();

    match ($ending) {
        'revoked' => app(ApiCredentialService::class)->revoke($credential, $this->world->identity->adminA, 'Leaked in a ticket'),
        'expired' => $this->travel(91)->days(),
    };

    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertUnauthorized()->assertJsonPath('error.code', 'credential_inactive');
})->with(['revoked', 'expired']);

test('the owner\'s current access is re-checked: a suspended membership, no role left, a lost permission or a disabled identity ends the credential', function (string $change): void {
    $owner = $this->world->identity->adminA;

    match ($change) {
        'membership suspended' => TenantMembership::query()->where('tenant_id', $this->tenant->id)->where('user_id', $owner->id)->update(['status' => AccessState::Suspended->value]),
        'permission lost' => $owner->syncRoles(['recruiter']),
        // A member without any role cannot enter the tenant, even holding the permission directly.
        'no role left' => tap($owner)->givePermissionTo('integrations.manage')->syncRoles([]),
        'identity disabled' => app(PlatformIdentityService::class)->disable($owner, 'Compromised'),
    };

    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertUnauthorized()->assertJsonPath('error.code', 'credential_inactive');
    apiAuthGet('/api/v1/me', $this->world->tokenB)->assertOk();
})->with(['membership suspended', 'no role left', 'permission lost', 'identity disabled']);

test('a tenant that is not usable has no API, whatever its credentials', function (TenantStatus $status): void {
    $this->tenant->forceFill(['status' => $status])->save();

    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertForbidden()->assertJsonPath('error.code', 'tenant_unavailable');
    apiAuthGet('/api/v1/me', $this->world->tokenB)->assertOk();
})->with([TenantStatus::Suspended, TenantStatus::Cancelled, TenantStatus::DeletionPending, TenantStatus::Deleted]);

test('a past-due tenant keeps its API during the grace period (SaaS-4)', function (): void {
    $this->tenant->forceFill(['status' => TenantStatus::PastDue])->save();

    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertOk();
});

test('without the api.access entitlement the API is refused; no plan grants it until the owner decides', function (): void {
    app(EntitlementOverrideService::class)->remove($this->tenant->fresh(), Entitlement::ApiAccess, 'Pilot ended');

    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertForbidden()->assertJsonPath('error.code', 'entitlement_required');
});

test('a scope only narrows: a missing scope is refused and audited, the rest still answers', function (): void {
    $token = ApiWorld::issue($this->tenant, $this->world->identity->adminA, 'Master data only', [ApiScope::MasterDataRead]);

    apiAuthGet('/api/v1/departments', $token)->assertOk();
    apiAuthGet('/api/v1/candidates', $token)->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
    apiAuthGet('/api/v1/candidates', $token)->assertForbidden();

    expect(AuditLog::query()->where('action', 'api_authorization_denied')->count())->toBe(1);
});

test('requests are limited per credential and per tenant, with Retry-After', function (): void {
    config(['api.rate_limits.per_credential' => 2]);

    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertOk();
    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertOk();
    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    apiAuthGet('/api/v1/me', $this->world->tokenB)->assertOk();

    config(['api.rate_limits.per_credential' => 100, 'api.rate_limits.per_tenant' => 2]);
    $second = ApiWorld::issue($this->tenant, $this->world->identity->adminA, 'Second');
    apiAuthGet('/api/v1/me', $second)->assertStatus(429);
});

test('repeated failed authentications from one address are slowed down', function (): void {
    config(['api.rate_limits.auth_failures_per_ip' => 3]);

    foreach (range(1, 3) as $i) {
        apiAuthGet('/api/v1/me', 'nonsense')->assertUnauthorized();
    }

    apiAuthGet('/api/v1/me', $this->world->tokenA)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

test('another tenant\'s records do not exist for a credential', function (): void {
    $theirs = TenantContext::current()->run($this->world->identity->beta, fn () => Candidate::factory()->create());

    apiAuthGet('/api/v1/candidates/'.$theirs->id, $this->world->tokenA)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    apiAuthGet('/api/v1/candidates/'.$theirs->id, $this->world->tokenB)->assertOk()->assertJsonPath('data.id', $theirs->id);
});
