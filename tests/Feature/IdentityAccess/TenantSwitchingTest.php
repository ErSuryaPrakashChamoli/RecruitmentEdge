<?php

use App\Enums\AccessState;
use App\Enums\TenantStatus;
use App\Filament\Pages\Auth\ChooseTenant;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\Identity\StaffAccessService;
use App\Services\Identity\TenantSelectionService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Feature\IdentityAccess\IdentityWorld;
use Tests\TestCase;

/*
 * SaaS-2: a person who belongs to several tenants moves between them only through their own
 * memberships. The tenant of every request is the one in its URL, checked against the membership on
 * every request (Livewire updates included); the default tenant is a convenience, never a grant.
 */
beforeEach(function (): void {
    Livewire::flushState();
    $this->world = IdentityWorld::build($this->tenant);
});

/**
 * Replays a Livewire update for the $component rendered in $page — what the browser sends when
 * the person interacts with an open page.
 *
 * @param  class-string  $component
 */
function switchingLivewireUpdate(TestCase $test, TestResponse $page, string $component): TestResponse
{
    // Each update is its own request: Livewire's per-request state (which routes' persistent
    // middleware already ran) is flushed between real requests, not between test requests.
    Livewire::flushState();

    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn (string $encoded): string => html_entity_decode($encoded))
        ->first(fn (string $json): bool => json_decode($json, true)['memo']['name'] === $component);

    return $test->withHeaders(['X-Livewire' => '1'])->postJson(EndpointResolver::updatePath(), ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]]);
}

test('a person with one tenant lands in it; a person with several and no default chooses', function (): void {
    $this->actingAs($this->world->personB)->get('/admin')->assertRedirect('/admin/acme');

    $this->actingAs($this->world->personA)->get('/admin')->assertRedirect(ChooseTenant::getUrl());

    $this->get(ChooseTenant::getUrl())->assertOk()
        ->assertSee('Acme Hiring')
        ->assertSee('Beta Staffing')
        ->assertSee('/admin/beta', false);
});

test('the chooser and the switcher list only tenants the person may enter — never another tenant', function (): void {
    $gamma = Tenant::factory()->create(['slug' => 'gamma', 'name' => 'Gamma Unrelated']);
    $delta = Tenant::factory()->create(['slug' => 'delta', 'name' => 'Delta Revoked']);
    TenantContext::current()->run($delta, fn () => (new RolePermissionSeeder)->run());
    IdentityWorld::join($this->world->personA, $delta, 'recruiter');
    TenantMembership::query()->where('tenant_id', $delta->id)->where('user_id', $this->world->personA->id)->update(['status' => AccessState::Revoked->value]);

    $this->actingAs($this->world->personA)->get(ChooseTenant::getUrl())->assertOk()->assertDontSee('Gamma Unrelated')->assertDontSee('Delta Revoked');

    expect($this->world->personA->fresh()->getTenants(Filament::getPanel('admin'))->pluck('slug')->all())->toBe(['acme', 'beta'])
        ->and($gamma->exists)->toBeTrue();
});

test('a chosen default is where the person lands; if it becomes inaccessible they choose again, never moved silently', function (): void {
    app(TenantSelectionService::class)->makeDefault($this->world->personA, $this->world->beta);

    $this->actingAs($this->world->personA->fresh())->get('/admin')->assertRedirect('/admin/beta');

    TenantContext::current()->run($this->world->beta, fn () => app(StaffAccessService::class)->suspend($this->world->personA, $this->world->adminB, 'Paused'));

    $this->actingAs($this->world->personA->fresh())->get('/admin')->assertRedirect(ChooseTenant::getUrl());
    $this->get(ChooseTenant::getUrl())->assertSee('no longer available')->assertDontSee('Beta Staffing');

    expect(AuditLog::query()->where('action', 'default_tenant_set')->exists())->toBeTrue()
        ->and(fn () => app(TenantSelectionService::class)->makeDefault($this->world->personA->fresh(), $this->world->beta))->toThrow(DomainException::class);
});

test('the default can be set from the chooser only to a tenant the person may enter', function (): void {
    $gamma = Tenant::factory()->create(['slug' => 'gamma']);
    $this->actingAs($this->world->personA);

    Livewire::test(ChooseTenant::class)->call('makeDefault', $gamma->id)->assertNotified('You can only make an organisation you can open your default.');
    Livewire::test(ChooseTenant::class)->call('makeDefault', $this->world->beta->id)->assertNotified('Beta Staffing is now your default organisation');

    expect(TenantMembership::query()->where('user_id', $this->world->personA->id)->where('is_default', true)->pluck('tenant_id')->all())->toBe([$this->world->beta->id]);
});

test('entering and switching tenants is audited in the tenant entered, once per switch', function (): void {
    $this->actingAs($this->world->personA);

    $this->get('/admin/acme')->assertOk();
    $this->get('/admin/acme/candidates')->assertOk();
    $this->get('/admin/beta')->assertOk();

    expect(AuditLog::query()->where('auditable_id', $this->world->personA->id)->whereIn('action', ['tenant_selected', 'tenant_switched'])->pluck('action')->all())->toBe(['tenant_selected'])
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('auditable_id', $this->world->personA->id)->where('action', 'tenant_switched')->count()))->toBe(1)
        ->and(TenantMembership::query()->where('tenant_id', $this->world->beta->id)->where('user_id', $this->world->personA->id)->value('last_selected_at'))->not->toBeNull();
});

test('changing the tenant slug never crosses: unknown and forbidden tenants look the same', function (): void {
    Tenant::factory()->create(['slug' => 'gamma']);
    $this->actingAs($this->world->personB);

    $this->get('/admin/acme')->assertOk();
    $this->get('/admin/beta')->assertNotFound();
    $this->get('/admin/beta/candidates')->assertNotFound();
    $this->get('/admin/gamma')->assertNotFound();
    $this->get('/admin/nonexistent')->assertNotFound();
});

test('the person\'s role in each tenant is that tenant\'s: manager in Acme, recruiter in Beta', function (): void {
    $this->actingAs($this->world->personA);

    // automation.view is a manager permission a recruiter does not hold.
    $this->get('/admin/acme/automation-rules')->assertOk();
    $this->get('/admin/beta/automation-rules')->assertForbidden();
    $this->get('/admin/beta/candidates')->assertOk();
});

test('a suspended member and an identity without any membership never reach a tenant', function (): void {
    $this->actingAs($this->world->personC)->get('/admin/acme')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->assertGuest();

    $this->actingAs($this->world->personD)->get('/admin/acme')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->assertGuest();
});

test('an open page stops working the moment the membership behind it is revoked — Livewire updates included', function (): void {
    $this->actingAs($this->world->personA);
    $page = $this->get('/admin/beta/candidates')->assertOk();

    switchingLivewireUpdate($this, $page, ListCandidates::class)->assertOk();

    TenantContext::current()->run($this->world->beta, fn () => app(StaffAccessService::class)->revoke($this->world->personA, $this->world->adminB, 'Contract ended'));

    $update = switchingLivewireUpdate($this, $page, ListCandidates::class);

    expect($update->status())->toBe(404);

    $this->get('/admin/beta/candidates')->assertNotFound();
    $this->get('/admin/acme')->assertOk();
});

test('a cancelled tenant closes at once for an open session', function (): void {
    $this->actingAs($this->world->personA);
    $page = $this->get('/admin/beta/candidates')->assertOk();

    $this->world->beta->update(['status' => TenantStatus::Cancelled]);

    expect(switchingLivewireUpdate($this, $page, ListCandidates::class)->status())->toBe(404);

    $this->get('/admin/beta')->assertNotFound();
});

test('a remembered tenant is re-checked before the profile uses it', function (): void {
    $this->actingAs($this->world->personA);
    $this->get('/admin/beta')->assertOk();

    expect(session(TenantSelectionService::SESSION_KEY))->toBe($this->world->beta->id);

    $this->get('/admin/profile')->assertOk()->assertSee($this->world->personAInBeta->employee_code);

    TenantContext::current()->run($this->world->beta, fn () => app(StaffAccessService::class)->suspend($this->world->personA, $this->world->adminB, 'Paused'));

    $this->get('/admin/profile')->assertOk()->assertSee($this->world->personAInAcme->employee_code)->assertDontSee($this->world->personAInBeta->employee_code);
});
