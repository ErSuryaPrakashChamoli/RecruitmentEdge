<?php

use App\Enums\ApiScope;
use App\Enums\Entitlement;
use App\Enums\TenantStatus;
use App\Filament\Pages\ApiCredentials;
use App\Models\ApiCredential;
use App\Models\AuditLog;
use App\Services\Api\ApiCredentialService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Api\ApiWorld;

/*
 * SaaS-6: credentials are issued by members holding integrations.manage, act as their owner,
 * carry only scopes the owner holds, are stored as a hash, shown once, rotated only by their owner
 * and revocable by any credential administrator at any time.
 */
beforeEach(function (): void {
    $this->world = ApiWorld::build($this->tenant);
    $this->service = app(ApiCredentialService::class);
});

test('only the secret\'s hash is stored, and the token is never in the audit trail', function (): void {
    $issued = $this->service->issue($this->world->identity->adminA, 'Payroll sync', [ApiScope::MasterDataRead], 30);
    [, $keyId, $secret] = explode('_', $issued['token'], 3);

    $row = (array) DB::table('api_credentials')->where('id', $issued['credential']->id)->first();

    expect($row['secret_hash'])->toBe(hash('sha256', $secret))
        ->and(json_encode($row))->not->toContain($secret)
        ->and($issued['credential']->toArray())->not->toHaveKey('secret_hash')
        ->and(json_encode(AuditLog::query()->get()))->not->toContain($secret)
        ->and(AuditLog::query()->where('action', 'api_credential_created')->latest('id')->first()->changes)->toMatchArray(['name' => 'Payroll sync', 'scopes' => ['master_data:read'], 'key' => 're_'.$keyId.'_…']);
});

test('issuing needs integrations.manage, a usable tenant, the entitlement, a valid name, scopes and expiry', function (): void {
    expect(fn () => $this->service->issue($this->world->identity->personB, 'x', [ApiScope::MasterDataRead], 30))->toThrow(DomainException::class, 'integrations.manage')
        ->and(fn () => $this->service->issue($this->world->identity->adminA, ' ', [ApiScope::MasterDataRead], 30))->toThrow(DomainException::class, 'Name')
        ->and(fn () => $this->service->issue($this->world->identity->adminA, 'x', [], 30))->toThrow(DomainException::class, 'scope')
        ->and(fn () => $this->service->issue($this->world->identity->adminA, 'x', [ApiScope::MasterDataRead], 366))->toThrow(DomainException::class, '365')
        ->and(fn () => $this->service->issue($this->world->identity->adminA, 'Acme ATS sync', [ApiScope::MasterDataRead], 30))->toThrow(DomainException::class, 'already exists');

    app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::ApiAccess, false, 'Off');
    expect(fn () => $this->service->issue($this->world->identity->adminA, 'x', [ApiScope::MasterDataRead], 30))->toThrow(DomainException::class, 'API access');

    app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::ApiAccess, true, 'On');
    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();
    expect(fn () => $this->service->issue($this->world->identity->adminA, 'x', [ApiScope::MasterDataRead], 30))->toThrow(DomainException::class, 'not active');
});

test('a member can only grant scopes backed by a permission they hold', function (): void {
    $member = $this->world->identity->personB;
    $member->syncPermissions(['integrations.manage']);
    $member->syncRoles([]);

    expect($this->service->grantableScopes($member->fresh()))->toBe([ApiScope::MasterDataRead])
        ->and(fn () => $this->service->issue($member->fresh(), 'x', [ApiScope::CandidatesRead], 30))->toThrow(DomainException::class, 'do not hold');
});

test('the number of active credentials is capped per tenant', function (): void {
    config(['api.credentials.max_active_per_tenant' => 2]);
    $this->service->issue($this->world->identity->adminA, 'Second', [ApiScope::MasterDataRead], 30);

    expect(fn () => $this->service->issue($this->world->identity->adminA, 'Third', [ApiScope::MasterDataRead], 30))->toThrow(DomainException::class, 'most active');

    $this->service->revoke(ApiCredential::query()->where('name', 'Second')->sole(), $this->world->identity->adminA, 'Not needed');
    expect($this->service->issue($this->world->identity->adminA, 'Third', [ApiScope::MasterDataRead], 30)['credential']->name)->toBe('Third');
});

test('only the owner rotates; the old token stops at once and the new one works', function (): void {
    $credential = ApiCredential::query()->where('name', 'Acme ATS sync')->sole();
    $otherAdmin = $this->world->identity->personA;
    $otherAdmin->givePermissionTo('integrations.manage');

    expect(fn () => $this->service->rotate($credential, $otherAdmin->fresh()))->toThrow(DomainException::class, 'owner');

    $rotated = $this->service->rotate($credential, $this->world->identity->adminA);

    $this->getJson('/api/v1/me', ApiWorld::headers($this->world->tokenA))->assertUnauthorized();
    $this->getJson('/api/v1/me', ApiWorld::headers($rotated['token']))->assertOk();
    expect(AuditLog::query()->where('action', 'api_credential_rotated')->count())->toBe(1);
});

test('any credential administrator revokes — also when the entitlement is gone', function (): void {
    $credential = ApiCredential::query()->where('name', 'Acme ATS sync')->sole();
    app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::ApiAccess, false, 'Incident');

    $revoked = $this->service->revoke($credential, $this->world->identity->adminA, 'Leaked');

    expect($revoked->revoked_at)->not->toBeNull()
        ->and(fn () => $this->service->revoke($credential, $this->world->identity->adminA, 'Again'))->toThrow(DomainException::class, 'already revoked')
        ->and(fn () => $this->service->revoke($credential, $this->world->identity->personB, 'x'))->toThrow(DomainException::class, 'integrations.manage')
        ->and(AuditLog::query()->where('action', 'api_credential_revoked')->sole()->reason)->toBe('Leaked');
});

test('the API Credentials page issues a credential and shows its token once, then revokes it', function (): void {
    $this->actingAs($this->world->identity->adminA);

    $page = Livewire::test(ApiCredentials::class)
        ->callTableAction('issue', data: ['name' => 'From the panel', 'scopes' => ['master_data:read'], 'expiry_days' => 30])
        ->assertActionMounted('revealSecret');

    $credential = ApiCredential::query()->where('name', 'From the panel')->sole();
    $token = $page->instance()->mountedActions[0]['arguments']['secret'] ?? null;

    expect($token)->toStartWith('re_'.$credential->key_id.'_')
        ->and(hash('sha256', explode('_', (string) $token, 3)[2]))->toBe($credential->secret_hash);

    Livewire::test(ApiCredentials::class)->callTableAction('revoke', $credential, ['reason' => 'Done'])->assertNotified('Credential revoked');
    expect($credential->fresh()->revoked_at)->not->toBeNull();

    $this->actingAs($this->world->identity->personB)->get(ApiCredentials::getUrl())->assertForbidden();
});
