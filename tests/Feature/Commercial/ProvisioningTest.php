<?php

use App\Enums\Entitlement;
use App\Enums\InvitationStatus;
use App\Enums\PlatformRole;
use App\Enums\TenantStatus;
use App\Mail\TenantInvitationMail;
use App\Models\AuditLog;
use App\Models\CandidateSource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Identity\TenantInvitationService;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Platform\Commercial\ProvisioningRequest;
use App\Services\Platform\Commercial\TenantDefaults;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\Platform\Commercial\TenantProvisioningService;
use App\Services\Platform\PlatformAccess;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

/*
 * SaaS-3: a tenant comes into being through one platform service — deterministic, idempotent,
 * never partly live. A repeated request finishes or returns the same tenant; a failure leaves it
 * in Provisioning (unusable) with the reason, and the next attempt resumes.
 */
beforeEach(function (): void {
    Mail::fake();
    app(PlanCatalogService::class)->sync();
    (new RolePermissionSeeder)->run();
});

function provisioningRequest(array $overrides = []): ProvisioningRequest
{
    return new ProvisioningRequest(...[
        'slug' => 'nova-hiring',
        'name' => 'Nova Hiring',
        'ownerEmail' => 'owner@nova.test',
        'planCode' => 'starter',
        'trialDays' => 14,
        'ownerName' => 'Nova Owner',
        ...$overrides,
    ]);
}

/**
 * @return array<string, int>
 */
function provisioningFootprint(Tenant $tenant): array
{
    return TenantContext::current()->run($tenant, fn (): array => [
        'tenants' => Tenant::query()->where('slug', $tenant->slug)->count(),
        'roles' => Role::query()->forCurrentTenant()->count(),
        'sources' => CandidateSource::query()->count(),
        'assignments' => TenantPlanAssignment::query()->count(),
        'invitations' => TenantInvitation::query()->count(),
    ]);
}

function provisioningAudit(Tenant $tenant, string $action): int
{
    return TenantContext::current()->run($tenant, fn (): int => AuditLog::query()->where('action', $action)->count());
}

test('a new tenant gets its defaults, its plan, one owner invitation and its trial — in that order', function (): void {
    $this->freezeSecond();

    $tenant = app(TenantProvisioningService::class)->provision(provisioningRequest());

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->isUsable())->toBeTrue()
        ->and($tenant->provisioned_at)->not->toBeNull()
        ->and($tenant->provisioning_error)->toBeNull()
        ->and($tenant->trial_ends_at->equalTo(now()->addDays(14)))->toBeTrue();

    TenantContext::current()->run($tenant, function (): void {
        $invitation = TenantInvitation::query()->sole();

        expect(Role::byKeyOrFail('chro')->exists)->toBeTrue()
            ->and(CandidateSource::query()->count())->toBeGreaterThan(0)
            ->and(TenantPlanAssignment::query()->where('is_current', true)->sole()->planVersion->plan->code)->toBe('starter')
            ->and($invitation->email)->toBe('owner@nova.test')
            ->and($invitation->grants_ownership)->toBeTrue()
            ->and($invitation->source)->toBe('provisioning')
            ->and($invitation->role_ids)->toBe([Role::byKeyOrFail('chro')->id])
            // Nobody is a member until the owner accepts: provisioning creates no person.
            ->and(TenantMembership::query()->count())->toBe(0);
    });

    expect(User::query()->where('email', 'owner@nova.test')->exists())->toBeFalse()
        ->and(provisioningAudit($tenant, 'tenant_provisioning_started'))->toBe(1)
        ->and(provisioningAudit($tenant, 'plan_assigned'))->toBe(1)
        ->and(provisioningAudit($tenant, 'trial_started'))->toBe(1)
        ->and(provisioningAudit($tenant, 'tenant_provisioned'))->toBe(1);
});

test('without a trial the tenant is activated directly', function (): void {
    $tenant = app(TenantProvisioningService::class)->provision(provisioningRequest(['trialDays' => null, 'planCode' => 'growth']));

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->trial_ends_at)->toBeNull()
        ->and(provisioningAudit($tenant, 'tenant_activated'))->toBe(1);
});

test('the same request again returns the same tenant and creates nothing twice', function (): void {
    $first = app(TenantProvisioningService::class)->provision(provisioningRequest());
    $footprint = provisioningFootprint($first);

    $again = app(TenantProvisioningService::class)->provision(provisioningRequest());

    expect($again->id)->toBe($first->id)
        ->and(provisioningFootprint($first))->toBe($footprint)
        ->and($footprint['tenants'])->toBe(1)
        ->and($footprint['assignments'])->toBe(1)
        ->and($footprint['invitations'])->toBe(1)
        ->and(provisioningAudit($first, 'tenant_provisioned'))->toBe(1)
        ->and(Mail::sent(TenantInvitationMail::class))->toHaveCount(1);
});

test('a different request for a taken slug is refused and the existing tenant is untouched', function (): void {
    $existing = app(TenantProvisioningService::class)->provision(provisioningRequest());
    $footprint = provisioningFootprint($existing);

    expect(fn () => app(TenantProvisioningService::class)->provision(provisioningRequest(['ownerEmail' => 'someone.else@evil.test'])))->toThrow(DomainException::class, 'already taken')
        ->and(fn () => app(TenantProvisioningService::class)->provision(provisioningRequest(['planCode' => 'enterprise'])))->toThrow(DomainException::class, 'already taken')
        ->and(provisioningFootprint($existing))->toBe($footprint)
        ->and(TenantContext::current()->run($existing, fn () => TenantInvitation::query()->where('email', 'someone.else@evil.test')->exists()))->toBeFalse();
});

test('a failed step rolls everything back, keeps the tenant unusable with the reason, and a retry finishes it', function (): void {
    $failing = new class extends TenantDefaults
    {
        public int $calls = 0;

        public function apply(): void
        {
            parent::apply();

            if (++$this->calls === 1) {
                throw new RuntimeException('Reference data unavailable');
            }
        }
    };
    app()->instance(TenantDefaults::class, $failing);

    expect(fn () => app(TenantProvisioningService::class)->provision(provisioningRequest()))->toThrow(RuntimeException::class);

    $tenant = Tenant::query()->where('slug', 'nova-hiring')->sole();

    expect($tenant->status)->toBe(TenantStatus::Provisioning)
        ->and($tenant->isUsable())->toBeFalse()
        ->and($tenant->provisioned_at)->toBeNull()
        ->and($tenant->provisioning_error)->toContain('Reference data unavailable')
        ->and(provisioningFootprint($tenant))->toMatchArray(['roles' => 0, 'sources' => 0, 'assignments' => 0, 'invitations' => 0])
        ->and(provisioningAudit($tenant, 'tenant_provisioning_failed'))->toBe(1)
        ->and(app(EntitlementService::class)->allows(Entitlement::ExportsData, $tenant))->toBeFalse();

    $finished = app(TenantProvisioningService::class)->provision(provisioningRequest());

    expect($finished->id)->toBe($tenant->id)
        ->and($finished->status)->toBe(TenantStatus::Trial)
        ->and($finished->provisioning_error)->toBeNull()
        ->and(provisioningFootprint($finished))->toMatchArray(['assignments' => 1, 'invitations' => 1])
        ->and(provisioningAudit($finished, 'tenant_provisioning_retried'))->toBe(1)
        ->and(provisioningAudit($finished, 'tenant_provisioned'))->toBe(1);
});

test('the owner accepts once: owner, CHRO, one seat — a new identity is created only if none exists', function (): void {
    $tenant = app(TenantProvisioningService::class)->provision(provisioningRequest());

    $owner = TenantContext::current()->run($tenant, fn () => app(TenantInvitationService::class)->acceptAsNewIdentity(TenantInvitation::query()->sole(), 'Nova Owner', 'Tr1cky-Ledger-Horse'));

    TenantContext::current()->run($tenant, function () use ($owner, $tenant): void {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->sole();

        expect($membership->user_id)->toBe($owner->id)
            ->and($membership->is_owner)->toBeTrue()
            ->and($owner->fresh()->hasRole('chro'))->toBeTrue()
            ->and(app(EntitlementService::class)->usage(Entitlement::MembersActiveMax))->toBe(1);
    });

    // Provisioning again never re-invites an owner who has joined.
    app(TenantProvisioningService::class)->provision(provisioningRequest());

    expect(TenantContext::current()->run($tenant, fn () => TenantInvitation::query()->count()))->toBe(1);
});

test('an owner who already has an identity joins with it — never a second account', function (): void {
    $existing = User::factory()->create(['email' => 'owner@nova.test'])->assignRole('recruiter');
    $identities = User::query()->count();

    $tenant = app(TenantProvisioningService::class)->provision(provisioningRequest(['ownerEmail' => 'Owner@Nova.test']));
    $membership = TenantContext::current()->run($tenant, fn () => app(TenantInvitationService::class)->accept(TenantInvitation::query()->sole(), $existing->fresh()));

    expect(User::query()->count())->toBe($identities)
        ->and($membership->user_id)->toBe($existing->id)
        ->and($membership->is_owner)->toBeTrue()
        // Their access elsewhere is unchanged: membership is per tenant.
        ->and($existing->fresh()->canAccessTenant($this->tenant))->toBeTrue();
});

test('only a platform administrator, or the platform console, can provision', function (): void {
    $tenantAdmin = User::factory()->create()->assignRole('chro');
    $operator = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => 'ops@platform.test']));
    app(PlatformAccess::class)->grant($operator, PlatformRole::Administrator, 'Commercial operations');

    expect(fn () => app(TenantProvisioningService::class)->provision(provisioningRequest(), $tenantAdmin))->toThrow(DomainException::class, 'platform administrator')
        ->and(Tenant::query()->where('slug', 'nova-hiring')->exists())->toBeFalse()
        ->and(app(TenantProvisioningService::class)->provision(provisioningRequest(), $operator)->status)->toBe(TenantStatus::Trial);
});

test('an internal plan, an invalid slug and a closed tenant\'s slug are refused', function (): void {
    expect(fn () => app(TenantProvisioningService::class)->provision(provisioningRequest(['planCode' => 'legacy'])))->toThrow(DomainException::class, 'not offered')
        ->and(fn () => provisioningRequest(['slug' => 'admin']))->toThrow(DomainException::class, 'reserved')
        ->and(fn () => provisioningRequest(['slug' => 'Bad Slug']))->toThrow(DomainException::class)
        ->and(fn () => provisioningRequest(['trialDays' => 365]))->toThrow(DomainException::class);

    $tenant = app(TenantProvisioningService::class)->provision(provisioningRequest());
    app(TenantLifecycleService::class)->cancel($tenant, 'Customer left');

    expect(fn () => app(TenantProvisioningService::class)->provision(provisioningRequest()))->toThrow(DomainException::class, 'closed tenant')
        ->and($tenant->fresh()->status)->toBe(TenantStatus::Cancelled);
});

test('the console command provisions, and reports a refusal without a stack trace', function (): void {
    $this->artisan('tenants:provision', ['slug' => 'nova-hiring', '--name' => 'Nova Hiring', '--owner' => 'owner@nova.test', '--plan' => 'starter', '--trial' => 14])
        ->expectsOutputToContain('nova-hiring is Trial')
        ->assertSuccessful();

    $this->artisan('tenants:provision', ['slug' => 'nova-hiring', '--name' => 'Other', '--owner' => 'owner@nova.test', '--plan' => 'starter'])
        ->expectsOutputToContain('already taken')
        ->assertFailed();

    expect(TenantContext::current()->run(Tenant::query()->where('slug', 'nova-hiring')->sole(), fn () => TenantInvitation::query()->where('status', InvitationStatus::Pending->value)->count()))->toBe(1);
});
