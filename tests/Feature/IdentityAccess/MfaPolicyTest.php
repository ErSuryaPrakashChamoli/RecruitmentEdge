<?php

use App\Filament\Pages\SecurityPolicy;
use App\Models\AuditLog;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Identity\MfaService;
use App\Services\Identity\TenantSecurityPolicyService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-2: a tenant may require MFA of every member. The requirement belongs to the identity: a
 * person who belongs to any tenant requiring it must use MFA wherever they sign in — switching
 * tenants, a direct URL or a Livewire request never waives it.
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
    config(['identity.mfa.enforce' => true]);
});

function mfaPolicyRequireInBeta(IdentityWorld $world): void
{
    TenantContext::current()->run($world->beta, fn () => app(TenantSecurityPolicyService::class)->setMfaRequired(true, $world->adminB, 'Customer contract'));
}

test('only access administrators change the policy, with a reason, and the change is audited in the tenant', function (): void {
    $service = app(TenantSecurityPolicyService::class);

    expect(fn () => $service->setMfaRequired(true, $this->world->personB, 'x'))->toThrow(DomainException::class, 'users.access.manage')
        ->and(fn () => $service->setMfaRequired(true, $this->world->adminA, ' '))->toThrow(DomainException::class, 'reason');

    $service->setMfaRequired(true, $this->world->adminA, 'Board decision');

    expect($this->world->acme->fresh()->mfa_required)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'mfa_policy_changed')->sole()->only(['old_values', 'changes']))->toMatchArray(['old_values' => ['mfa_required' => false], 'changes' => ['mfa_required' => true, 'reason' => 'Board decision', 'by_user_id' => $this->world->adminA->id]])
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'mfa_policy_changed')->count()))->toBe(0);
});

test('the security page is for access administrators and changes the policy through the service', function (): void {
    $this->actingAs($this->world->personB)->get('/admin/acme/security-policy')->assertForbidden();

    $this->actingAs($this->world->adminA);
    Livewire::test(SecurityPolicy::class)->callAction('requireMfa', ['reason' => 'Board decision'])->assertNotified('MFA is now required for every member');

    expect($this->world->acme->fresh()->mfa_required)->toBeTrue();
});

test('a tenant\'s policy applies to its members only, and to them everywhere they sign in', function (): void {
    $mfa = app(MfaService::class);
    mfaPolicyRequireInBeta($this->world);

    expect($mfa->isRequiredFor($this->world->personB->fresh()))->toBeFalse()
        ->and($mfa->isRequiredFor($this->world->personE->fresh()))->toBeTrue()
        ->and(TenantContext::current()->runWithoutTenant(fn () => $mfa->isRequiredFor($this->world->personE->fresh())))->toBeTrue();
});

test('switching to a tenant without the policy never waives it: direct URLs, the chooser and Livewire all require enrolment', function (): void {
    mfaPolicyRequireInBeta($this->world);
    $setUp = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

    $this->actingAs($this->world->personE);

    $this->get('/admin/acme')->assertRedirect($setUp);
    $this->get('/admin/acme/candidates')->assertRedirect($setUp);
    $this->get('/admin/beta')->assertRedirect($setUp);
    $this->get('/admin/organisations')->assertRedirect($setUp);

    $this->actingAs($this->world->personB)->get('/admin/acme')->assertOk();
});

test('a person whose tenant requires MFA cannot turn it off, in any tenant', function (): void {
    mfaPolicyRequireInBeta($this->world);
    $person = $this->world->personE->fresh();
    $person->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    expect(fn () => $person->fresh()->saveAppAuthenticationSecret(null))->toThrow(DomainException::class, 'required')
        ->and(fn () => TenantContext::current()->runWithoutTenant(fn () => $person->fresh()->saveAppAuthenticationSecret(null)))->toThrow(DomainException::class, 'required');
});

test('the policy of a tenant the person no longer belongs to never follows them', function (): void {
    mfaPolicyRequireInBeta($this->world);

    TenantMembership::query()->where('tenant_id', $this->world->beta->id)->where('user_id', $this->world->personE->id)->update(['status' => 'revoked']);

    expect(app(MfaService::class)->isRequiredFor(User::query()->find($this->world->personE->id)))->toBeFalse();
});
