<?php

use App\Enums\PlatformCapability;
use App\Enums\PlatformRole;
use App\Filament\Platform\Pages\ComplianceExports;
use App\Filament\Platform\Pages\DeletionRequests;
use App\Filament\Platform\Pages\OperationalEvents;
use App\Filament\Platform\Pages\PlatformAudit;
use App\Filament\Platform\Pages\SupportGrants;
use App\Filament\Platform\Pages\TenantDetail;
use App\Filament\Platform\Pages\Tenants;
use App\Services\Platform\PlatformAccess;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Platform\PlatformIdentityService;
use Filament\Facades\Filament;
use Tests\Feature\Platform\PlatformWorld;

/*
 * SaaS-5: the platform control plane is a separate panel for platform operators only — a tenant
 * role grants nothing there, a platform role grants nothing in a tenant, every page follows the
 * operator's capabilities, MFA is required, and a revoked operator's session ends on its next request.
 */
beforeEach(function (): void {
    $this->world = PlatformWorld::build($this->tenant);
});

test('every platform page answers an operator holding every role', function (): void {
    $this->actingAs($this->world->everything);

    foreach (['/platform', '/platform/tenants', '/platform/tenants/'.$this->tenant->id, '/platform/deletion-requests', '/platform/support-grants', '/platform/compliance-exports', '/platform/platform-audit', '/platform/operational-events'] as $uri) {
        $this->get($uri)->assertOk();
    }
});

test('a tenant administrator is not a platform operator and is signed out of the platform panel', function (): void {
    $chro = $this->world->identity->adminA;

    expect($chro->canAccessPanel(Filament::getPanel('platform')))->toBeFalse()
        ->and($chro->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();

    $this->actingAs($chro)->get('/platform/tenants')->assertRedirect(Filament::getPanel('platform')->getLoginUrl());
    $this->assertGuest();
});

test('a platform operator is no tenant member and never reaches the tenant panel', function (): void {
    $operator = $this->world->everything;

    expect($operator->memberships()->count())->toBe(0)
        ->and($operator->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($operator->canAccessTenant($this->tenant))->toBeFalse();

    $this->actingAs($operator)->get('/admin/acme')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});

test('capabilities follow the operator\'s roles, and each page follows its capability', function (): void {
    $authorization = app(PlatformAuthorization::class);

    expect(collect(PlatformCapability::cases())->filter(fn (PlatformCapability $capability) => $authorization->can($this->world->support, $capability))->values()->all())
        ->toBe([PlatformCapability::TenantsView, PlatformCapability::SupportManage, PlatformCapability::OperationsView])
        ->and(collect(PlatformCapability::cases())->filter(fn (PlatformCapability $capability) => $authorization->can($this->world->compliance, $capability))->values()->all())
        ->toBe([PlatformCapability::TenantsView, PlatformCapability::ComplianceManage, PlatformCapability::AuditView, PlatformCapability::DeletionManage])
        ->and(collect(PlatformCapability::cases())->filter(fn (PlatformCapability $capability) => $authorization->can($this->world->administrator, $capability))->values()->all())
        ->toBe([PlatformCapability::TenantsView, PlatformCapability::TenantsManage, PlatformCapability::AuditView, PlatformCapability::OperationsView, PlatformCapability::DeletionManage, PlatformCapability::CommercialManage]);

    $this->actingAs($this->world->support);
    $this->get(Tenants::getUrl(panel: 'platform'))->assertOk();
    $this->get(SupportGrants::getUrl(panel: 'platform'))->assertOk();
    $this->get(DeletionRequests::getUrl(panel: 'platform'))->assertForbidden();
    $this->get(ComplianceExports::getUrl(panel: 'platform'))->assertForbidden();
    $this->get(PlatformAudit::getUrl(panel: 'platform'))->assertForbidden();

    $this->actingAs($this->world->compliance);
    $this->get(ComplianceExports::getUrl(panel: 'platform'))->assertOk();
    $this->get(OperationalEvents::getUrl(panel: 'platform'))->assertForbidden();
    $this->get(SupportGrants::getUrl(panel: 'platform'))->assertForbidden();
});

test('a disabled identity holds no platform capability', function (): void {
    app(PlatformIdentityService::class)->disable($this->world->administrator, 'Compromised');

    expect(app(PlatformAuthorization::class)->can($this->world->administrator->fresh(), PlatformCapability::TenantsView))->toBeFalse()
        ->and(app(PlatformAuthorization::class)->isOperator($this->world->administrator->fresh()))->toBeFalse();
});

test('a revoked operator is signed out on the next request', function (): void {
    $this->actingAs($this->world->support)->get(TenantDetail::getUrl(['tenant' => $this->tenant->id], panel: 'platform'))->assertOk();

    app(PlatformAccess::class)->revoke($this->world->support, PlatformRole::Support, 'Left the rota');

    $this->get(TenantDetail::getUrl(['tenant' => $this->tenant->id], panel: 'platform'))->assertRedirect(Filament::getPanel('platform')->getLoginUrl());
    $this->assertGuest();
});

test('every platform operator must enrol MFA before any platform page', function (): void {
    config(['identity.mfa.enforce' => true]);

    $this->actingAs($this->world->compliance)->get('/platform/tenants')->assertRedirect(Filament::getPanel('platform')->getSetUpRequiredMultiFactorAuthenticationUrl());
});
