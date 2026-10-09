<?php

use App\Filament\Platform\Pages\CreateTenant;
use App\Filament\Platform\Pages\TenantDetail;
use App\Mail\TenantInvitationMail;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Platform\Commercial\ProvisioningRequest;
use App\Services\Platform\TenantCommercialService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Platform\PlatformWorld;

/*
 * Platform commercial UI: creating a tenant from the platform panel goes through the same
 * provisioning service as tenants:provision — authorised, attributed, idempotent — and the wizard
 * never supplies a default of its own.
 */
beforeEach(function (): void {
    Mail::fake();
    app(PlanCatalogService::class)->sync();
    $this->world = PlatformWorld::build($this->tenant);
    Filament::setCurrentPanel('platform');
});

/**
 * @return array<string, mixed>
 */
function createTenantForm(array $overrides = []): array
{
    return [
        'name' => 'Nova Hiring',
        'legal_name' => 'Nova Hiring Ltd',
        'slug' => 'nova-hiring',
        'owner_name' => 'Nova Owner',
        'owner_email' => 'owner@nova.test',
        'plan' => 'growth',
        'trial' => true,
        'trial_days' => 14,
        ...$overrides,
    ];
}

test('an administrator creates a tenant through the provisioning service: plan, trial, owner invited, attributed, then the tenant detail', function (): void {
    $this->actingAs($this->world->administrator);

    Livewire::test(CreateTenant::class)
        ->fillForm(createTenantForm())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Tenant created — the owner has been invited')
        ->assertRedirect(TenantDetail::getUrl(['tenant' => Tenant::query()->where('slug', 'nova-hiring')->value('id')]));

    $tenant = Tenant::query()->where('slug', 'nova-hiring')->sole();
    $defaults = new ProvisioningRequest(slug: 'any-slug', name: 'Any', ownerEmail: 'any@any.test', planCode: 'growth');

    expect($tenant->name)->toBe('Nova Hiring')
        ->and($tenant->legal_name)->toBe('Nova Hiring Ltd')
        ->and($tenant->trial_ends_at)->not->toBeNull()
        // Region left empty: the provisioning request's own defaults, not values from the UI.
        ->and([$tenant->timezone, $tenant->locale, $tenant->currency, $tenant->country])->toBe([$defaults->timezone, $defaults->locale, $defaults->currency, $defaults->country])
        ->and(AuditLog::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('action', 'tenant_provisioned')->sole())
        ->actor_kind->toBe('platform')
        ->user_id->toBe($this->world->administrator->id);

    Mail::assertSent(TenantInvitationMail::class, fn (TenantInvitationMail $mail): bool => $mail->hasTo('owner@nova.test'));
});

test('region values the operator enters are stored as given, for any country', function (): void {
    $this->actingAs($this->world->administrator);

    Livewire::test(CreateTenant::class)
        ->fillForm(createTenantForm(['trial' => false, 'country' => 'gb', 'timezone' => 'Europe/London', 'currency' => 'gbp', 'locale' => 'en-GB']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tenant::query()->where('slug', 'nova-hiring')->sole())
        ->country->toBe('GB')
        ->timezone->toBe('Europe/London')
        ->currency->toBe('GBP')
        ->locale->toBe('en-GB')
        ->trial_ends_at->toBeNull();
});

test('submitting the same tenant twice provisions it once, invites the owner once, and says the second time that nothing was created', function (): void {
    $this->actingAs($this->world->administrator);

    Livewire::test(CreateTenant::class)->fillForm(createTenantForm())->call('create')->assertHasNoFormErrors()->assertNotified('Tenant created — the owner has been invited');
    Livewire::test(CreateTenant::class)->fillForm(createTenantForm())->call('create')->assertHasNoFormErrors()->assertNotified('Nothing new was created');

    expect(Tenant::query()->where('slug', 'nova-hiring')->count())->toBe(1);
    Mail::assertSentCount(1);
});

test('the service refuses a taken slug with other details, and a reserved slug', function (?array $first, array $second): void {
    $this->actingAs($this->world->administrator);

    if ($first !== null) {
        Livewire::test(CreateTenant::class)->fillForm(createTenantForm($first))->call('create')->assertHasNoFormErrors();
    }

    $before = Tenant::query()->count();

    Livewire::test(CreateTenant::class)
        ->fillForm(createTenantForm($second))
        ->call('create')
        ->assertNotified('Not done')
        ->assertNoRedirect();

    expect(Tenant::query()->count())->toBe($before);
})->with([
    'a slug provisioned for another company' => [[], ['name' => 'Other Company', 'owner_email' => 'someone@other.test']],
    'a reserved slug' => [null, ['slug' => 'platform']],
]);

test('a slug that belongs to a tenant from before provisioning is left unchanged and reported, not re-provisioned', function (): void {
    $this->actingAs($this->world->administrator);
    $acme = $this->tenant->fresh();

    Livewire::test(CreateTenant::class)
        ->fillForm(createTenantForm(['slug' => 'acme', 'plan' => 'starter']))
        ->call('create')
        ->assertNotified('Nothing new was created');

    expect($this->tenant->fresh()->only(['name', 'status', 'provisioned_at', 'entitlement_version']))->toEqual($acme->only(['name', 'status', 'provisioned_at', 'entitlement_version']))
        ->and(AuditLog::query()->withoutTenancy()->where('tenant_id', $acme->id)->whereIn('action', ['tenant_provisioned', 'plan_assigned'])->exists())->toBeFalse();
    Mail::assertNothingSent();
});

test('only plans offered to new tenants can be chosen — never the internal legacy plan', function (): void {
    $this->actingAs($this->world->administrator);

    Livewire::test(CreateTenant::class)
        ->fillForm(createTenantForm(['plan' => 'legacy']))
        ->call('create')
        ->assertHasFormErrors(['plan']);

    expect(Tenant::query()->where('slug', 'nova-hiring')->exists())->toBeFalse();
});

test('the shape of the input is checked before anything is sent', function (): void {
    $this->actingAs($this->world->administrator);

    Livewire::test(CreateTenant::class)
        ->fillForm(createTenantForm(['owner_email' => 'not-an-email', 'country' => 'GBR', 'currency' => 'POUND']))
        ->call('create')
        ->assertHasFormErrors(['owner_email', 'country', 'currency']);

    expect(Tenant::query()->where('slug', 'nova-hiring')->exists())->toBeFalse();
});

test('only a tenant manager reaches the page, and the adapter refuses anyone else on its own', function (): void {
    foreach ([$this->world->support, $this->world->compliance] as $operator) {
        $this->actingAs($operator)->get(CreateTenant::getUrl(panel: 'platform'))->assertForbidden();

        expect(fn () => app(TenantCommercialService::class)->provision(['slug' => 'nova-hiring', 'name' => 'Nova', 'owner_email' => 'owner@nova.test', 'plan' => 'growth'], $operator))
            ->toThrow(DomainException::class);
    }

    // A tenant's own administrator is no platform operator: signed out of the platform panel.
    $this->actingAs($this->world->identity->adminA)->get(CreateTenant::getUrl(panel: 'platform'))->assertRedirect(Filament::getPanel('platform')->getLoginUrl());
    $this->actingAs($this->world->administrator)->get(CreateTenant::getUrl(panel: 'platform'))->assertOk();

    expect(Tenant::query()->where('slug', 'nova-hiring')->exists())->toBeFalse();
});
