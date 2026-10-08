<?php

use App\Enums\PlatformRole;
use App\Enums\SupportGrantStatus;
use App\Enums\SupportScope;
use App\Enums\TenantStatus;
use App\Filament\Pages\SupportAccess;
use App\Filament\Platform\Pages\SupportWorkspace;
use App\Models\AuditLog;
use App\Models\SupportAccessGrant;
use App\Services\Platform\PlatformAccess;
use App\Services\Platform\SupportAccessService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Platform\PlatformWorld;

/*
 * SaaS-5: support access is asked for by a support operator, decided by the tenant's own access
 * administrators (within what was asked), scoped, time-bound, revocable, and audited on every use.
 * The support workspace is read-only and stops answering the moment the grant ends — no
 * impersonation, and no stale page keeps working.
 */
beforeEach(function (): void {
    $this->world = PlatformWorld::build($this->tenant);
    $this->service = app(SupportAccessService::class);
});

function supportRequestFor(PlatformWorld $world, array $scopes = [SupportScope::Diagnostics, SupportScope::People], int $minutes = 60): SupportAccessGrant
{
    return app(SupportAccessService::class)->request($world->identity->acme, $scopes, $minutes, 'Ticket 4711: imports failing', $world->support);
}

test('a support request is the tenant\'s to decide: it opens nothing, notifies its access administrators and is audited', function (): void {
    $grant = supportRequestFor($this->world);

    expect($grant->status)->toBe(SupportGrantStatus::Requested)
        ->and($grant->isActive())->toBeFalse()
        ->and($grant->tenant_id)->toBe($this->tenant->id)
        ->and($this->world->identity->adminA->notifications()->where('data->title', '[Support] Support access requested')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'support_access_requested')->sole())->actor_kind->toBe('platform')
        ->and(AuditLog::query()->where('action', 'support_access_requested')->sole()->user_id)->toBe($this->world->support->id)
        ->and(supportRequestFor($this->world)->is($grant))->toBeTrue();

    $this->actingAs($this->world->support)->get(SupportWorkspace::getUrl(['grant' => $grant->id], panel: 'platform'))->assertForbidden();
});

test('only a support operator asks, with a reason, a scope and a bounded duration, of a tenant open to support', function (): void {
    expect(fn () => $this->service->request($this->tenant, [SupportScope::Diagnostics], 60, 'Help', $this->world->compliance))->toThrow(DomainException::class, 'platform.support.manage')
        ->and(fn () => $this->service->request($this->tenant, [], 60, 'Help', $this->world->support))->toThrow(DomainException::class, 'scope')
        ->and(fn () => $this->service->request($this->tenant, [SupportScope::Audit], 481, 'Help', $this->world->support))->toThrow(DomainException::class, 'minutes')
        ->and(fn () => $this->service->request($this->tenant, [SupportScope::Audit], 60, ' ', $this->world->support))->toThrow(DomainException::class, 'reason');

    $this->tenant->forceFill(['status' => TenantStatus::Cancelled])->save();

    expect(fn () => $this->service->request($this->tenant->fresh(), [SupportScope::Audit], 60, 'Help', $this->world->support))->toThrow(DomainException::class, 'Cancelled')
        ->and(SupportAccessGrant::query()->withoutTenancy()->count())->toBe(0);
});

test('the tenant approves within what was asked; the operator then sees only the approved scopes, each use audited', function (): void {
    $grant = supportRequestFor($this->world);
    $admin = $this->world->identity->adminA;

    expect(fn () => $this->service->approve($grant, [SupportScope::Audit], 30, $admin))->toThrow(DomainException::class, 'scopes that were asked')
        ->and(fn () => $this->service->approve($grant, [SupportScope::Diagnostics], 61, $admin))->toThrow(DomainException::class, '60 minutes')
        ->and(fn () => $this->service->approve($grant, [SupportScope::Diagnostics], 30, $this->world->identity->personB))->toThrow(DomainException::class, 'users.access.manage');

    $approved = $this->service->approve($grant, [SupportScope::Diagnostics], 30, $admin);

    expect($approved->status)->toBe(SupportGrantStatus::Active)
        ->and($approved->scopes)->toBe(['diagnostics'])
        ->and($approved->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(29.0)->toBeLessThanOrEqual(30.0);

    $this->actingAs($this->world->support);
    $this->get(SupportWorkspace::getUrl(['grant' => $grant->id, 'scope' => 'diagnostics'], panel: 'platform'))->assertOk()->assertSee('Organisation');
    $this->get(SupportWorkspace::getUrl(['grant' => $grant->id, 'scope' => 'people'], panel: 'platform'))->assertForbidden();

    $use = AuditLog::query()->where('action', 'support_access_used')->sole();
    expect($use->actor_kind)->toBe('platform')
        ->and($use->user_id)->toBe($this->world->support->id)
        ->and($use->changes['scope'])->toBe('diagnostics')
        ->and($grant->fresh()->use_count)->toBe(1);

    $this->actingAs($this->world->everything)->get(SupportWorkspace::getUrl(['grant' => $grant->id], panel: 'platform'))->assertForbidden();
});

test('another tenant\'s administrator neither sees, decides nor revokes a tenant\'s request', function (): void {
    $grant = supportRequestFor($this->world);

    TenantContext::current()->run($this->world->identity->beta, function () use ($grant): void {
        expect(SupportAccessGrant::query()->count())->toBe(0)
            ->and(fn () => $this->service->approve($grant, [SupportScope::Diagnostics], 30, $this->world->identity->adminB))->toThrow(DomainException::class, 'another organisation')
            ->and(fn () => $this->service->deny($grant, 'No', $this->world->identity->adminB))->toThrow(DomainException::class, 'another organisation')
            ->and(fn () => $this->service->revoke($grant, $this->world->identity->adminB, 'Not ours'))->toThrow(DomainException::class, 'another organisation');
    });

    expect($grant->fresh()->status)->toBe(SupportGrantStatus::Requested);
});

test('the support workspace fails closed on a page left open: revocation, expiry and a lost role each stop it on the next update', function (string $ending): void {
    $grant = $this->service->approve(supportRequestFor($this->world), [SupportScope::Diagnostics, SupportScope::People], 60, $this->world->identity->adminA);
    Filament::setCurrentPanel('platform');
    $this->actingAs($this->world->support);

    $page = Livewire::test(SupportWorkspace::class, ['grant' => $grant->id])->assertOk();

    match ($ending) {
        'revoked' => TenantContext::current()->run($this->tenant, fn () => $this->service->revoke($grant, $this->world->identity->adminA, 'Done')),
        'expired' => $this->travel(61)->minutes(),
        'role lost' => app(PlatformAccess::class)->revoke($this->world->support, PlatformRole::Support, 'Rota ended'),
        'tenant closed' => $this->tenant->forceFill(['status' => TenantStatus::Cancelled])->save(),
    };

    $page->call('$refresh')->assertForbidden();
})->with(['revoked', 'expired', 'role lost', 'tenant closed']);

test('switching scope is another audited use, and only to a scope the grant covers', function (): void {
    $grant = $this->service->approve(supportRequestFor($this->world), [SupportScope::People], 60, $this->world->identity->adminA);
    Filament::setCurrentPanel('platform');
    $this->actingAs($this->world->support);

    Livewire::test(SupportWorkspace::class, ['grant' => $grant->id])->assertForbidden();

    Livewire::withQueryParams(['scope' => 'people'])->test(SupportWorkspace::class, ['grant' => $grant->id])
        ->assertOk()
        ->assertCanSeeTableRecords([$this->world->identity->adminA, $this->world->identity->personB])
        ->assertCanNotSeeTableRecords([$this->world->identity->adminB])
        ->assertActionHidden('scope_audit');

    expect(AuditLog::query()->where('action', 'support_access_used')->pluck('changes')->pluck('scope')->all())->toBe(['people']);
});

test('the tenant denies or revokes on its own Support Access page', function (): void {
    $grant = supportRequestFor($this->world);
    $this->actingAs($this->world->identity->adminA);

    Livewire::test(SupportAccess::class)
        ->assertCanSeeTableRecords([$grant])
        ->callTableAction('deny', $grant, ['reason' => 'Not now'])
        ->assertHasNoTableActionErrors();

    expect($grant->fresh()->status)->toBe(SupportGrantStatus::Denied)
        ->and(AuditLog::query()->where('action', 'support_access_denied')->sole()->user_id)->toBe($this->world->identity->adminA->id);

    $second = supportRequestFor($this->world, [SupportScope::Audit], 45);

    Livewire::test(SupportAccess::class)
        ->callTableAction('approve', $second, ['scopes' => ['audit'], 'minutes' => 45])
        ->assertHasNoTableActionErrors()
        ->callTableAction('revoke', $second, ['reason' => 'Resolved'])
        ->assertHasNoTableActionErrors();

    expect($second->fresh()->status)->toBe(SupportGrantStatus::Revoked);

    $this->actingAs($this->world->identity->personB)->get(SupportAccess::getUrl())->assertForbidden();
});

test('the sweep records ended grants and lapsed requests; an ended grant opened nothing even before it ran', function (): void {
    $active = $this->service->approve(supportRequestFor($this->world), [SupportScope::Diagnostics], 15, $this->world->identity->adminA);
    $this->travel(16)->minutes();

    expect(fn () => $this->service->assertUsable($active->fresh(), SupportScope::Diagnostics, $this->world->support))->toThrow(DomainException::class, 'not active');

    $pending = app(SupportAccessService::class)->request($this->world->identity->beta, [SupportScope::Audit], 30, 'Ticket 9', $this->world->support);
    $this->travel(73)->hours();

    $this->artisan('platform:sweep')->assertSuccessful();

    expect($active->fresh()->status)->toBe(SupportGrantStatus::Expired)
        ->and(SupportAccessGrant::query()->withoutTenancy()->find($pending->id)->status)->toBe(SupportGrantStatus::Expired)
        ->and(AuditLog::query()->withoutTenancy()->whereIn('action', ['support_access_expired', 'support_access_request_lapsed'])->count())->toBe(2);
});
