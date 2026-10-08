<?php

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\PlatformOperator;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use App\Services\Platform\PlatformIdentityService;
use App\Services\Platform\SupportAccessService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-2: the platform plane is separate from the tenant plane. A platform operator is never a
 * tenant member, holds no tenant role and reaches no tenant data; support access is a tenant's own
 * explicit, time-bound, revocable grant (and opens nothing until SaaS-5 builds the console).
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
    $this->operatorUser = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => 'support@platform.test']));
    $this->operator = app(PlatformAccess::class)->grant($this->operatorUser, PlatformRole::Support, 'Support rota');
});

test('a platform role is granted and revoked on the command line, audited in the platform stream only', function (): void {
    $this->artisan('platform:operator grant support@platform.test compliance --reason="Audit season"')->assertSuccessful();

    expect(app(PlatformAccess::class)->holds($this->operatorUser, PlatformRole::Compliance))->toBeTrue()
        ->and(TenantContext::current()->runWithoutTenant(fn () => AuditLog::query()->withoutTenancy()->where('action', 'platform_role_granted')->whereNull('tenant_id')->count()))->toBe(2)
        ->and(AuditLog::query()->where('action', 'platform_role_granted')->exists())->toBeFalse();

    $this->artisan('platform:operator revoke support@platform.test compliance --reason="Done"')->assertSuccessful();
    $this->artisan('platform:operator grant support@platform.test support')->assertFailed();

    expect(app(PlatformAccess::class)->holds($this->operatorUser, PlatformRole::Compliance))->toBeFalse();
});

test('a platform operator is not a member of any tenant and reaches no tenant, page or record', function (): void {
    $operator = $this->operatorUser->fresh();

    expect($operator->memberships()->count())->toBe(0)
        ->and($operator->accessibleTenants())->toBeEmpty()
        ->and($operator->canAccessTenant($this->world->acme))->toBeFalse()
        ->and($operator->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($operator->can('candidates.viewAny'))->toBeFalse()
        ->and($operator->can('view', Candidate::factory()->create()))->toBeFalse();

    $this->actingAs($operator)->get('/admin/acme')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});

test('a tenant grants a support operator time-bound access, revocable, audited — and it opens nothing in SaaS-2', function (): void {
    $grant = app(SupportAccessService::class)->grant($this->operator, 60, 'Ticket 4711: export failing', $this->world->adminA);

    expect($grant->tenant_id)->toBe($this->world->acme->id)
        ->and($grant->isActive())->toBeTrue()
        ->and(app(SupportAccessService::class)->activeGrant($this->operator)?->is($grant))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->beta, fn () => app(SupportAccessService::class)->activeGrant($this->operator)))->toBeNull()
        ->and($this->operatorUser->fresh()->canAccessTenant($this->world->acme))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'support_access_granted')->sole()->getAttribute('changes'))->toMatchArray(['minutes' => 60, 'by_user_id' => $this->world->adminA->id]);

    $this->travel(61)->minutes();

    expect($grant->fresh()->isActive())->toBeFalse()
        ->and(app(SupportAccessService::class)->activeGrant($this->operator))->toBeNull();
});

test('support grants need users.access.manage, an active support operator, a reason and a bounded duration', function (): void {
    $service = app(SupportAccessService::class);
    $compliance = app(PlatformAccess::class)->grant($this->operatorUser, PlatformRole::Compliance, 'Audits');

    expect(fn () => $service->grant($this->operator, 60, 'Help', $this->world->personB))->toThrow(DomainException::class, 'users.access.manage')
        ->and(fn () => $service->grant($compliance, 60, 'Help', $this->world->adminA))->toThrow(DomainException::class, 'support operator')
        ->and(fn () => $service->grant($this->operator, 60 * 24 * 7, 'Help', $this->world->adminA))->toThrow(DomainException::class, 'minutes')
        ->and(fn () => $service->grant($this->operator, 60, ' ', $this->world->adminA))->toThrow(DomainException::class, 'reason')
        ->and(SupportAccessGrant::query()->count())->toBe(0);

    $grant = $service->grant($this->operator, 30, 'Help', $this->world->adminA);
    $service->revoke($grant, $this->world->adminA, 'Resolved');

    expect($grant->fresh()->isActive())->toBeFalse()
        ->and(fn () => $service->revoke($grant, $this->world->adminA, 'Again'))->toThrow(DomainException::class, 'already revoked');
});

test('another tenant never sees or revokes a tenant\'s support grants', function (): void {
    $grant = app(SupportAccessService::class)->grant($this->operator, 30, 'Help', $this->world->adminA);

    TenantContext::current()->run($this->world->beta, function () use ($grant): void {
        expect(SupportAccessGrant::query()->count())->toBe(0)
            ->and($this->world->adminB->can('view', $grant))->toBeFalse()
            ->and(fn () => app(SupportAccessService::class)->revoke($grant, $this->world->adminB, 'Not ours'))->toThrow(DomainException::class);
    });

    expect($grant->fresh()->revoked_at)->toBeNull();
});

test('the platform can disable a whole identity: every tenant closes and every session ends; enabling restores each tenant\'s own decision', function (): void {
    $person = $this->world->personA;
    $epoch = $person->fresh()->session_epoch;

    $this->artisan('identity:disable asha@example.test --reason="Compromised account"')->assertSuccessful();

    expect($person->fresh()->accessibleTenants())->toBeEmpty()
        ->and($person->fresh()->can('candidates.viewAny'))->toBeFalse()
        ->and($person->fresh()->session_epoch)->toBe($epoch + 1)
        ->and(AuditLog::query()->where('action', 'identity_disabled')->where('auditable_id', $person->id)->exists())->toBeTrue()
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'identity_disabled')->where('auditable_id', $person->id)->exists()))->toBeTrue();

    $this->actingAs($person->fresh())->get('/admin/acme')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    app(PlatformIdentityService::class)->enable($person->fresh(), 'Recovered');

    expect($person->fresh()->accessibleTenants()->pluck('slug')->all())->toBe(['acme', 'beta'])
        ->and(fn () => $person->fresh()->forceFill(['disabled_at' => now()])->save())->toThrow(LogicException::class, 'PlatformIdentityService');
});

test('platform operator records never carry a tenant', function (): void {
    expect(PlatformOperator::query()->first()->getAttributes())->not->toHaveKey('tenant_id');
});
