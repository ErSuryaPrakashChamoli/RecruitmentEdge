<?php

use App\Enums\PlatformEventSeverity;
use App\Enums\TenantStatus;
use App\Filament\Platform\Pages\OperationalEvents;
use App\Filament\Platform\Pages\PlatformAudit;
use App\Filament\Platform\Pages\TenantDetail;
use App\Filament\Platform\Pages\Tenants;
use App\Mail\PlatformEventMail;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ComplianceExport;
use App\Models\Employee;
use App\Models\PlatformEvent;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Platform\Commercial\ProvisioningRequest;
use App\Services\Platform\Commercial\TenantDefaults;
use App\Services\Platform\Commercial\TenantProvisioningService;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\TenantOwnershipService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Platform\PlatformWorld;

/*
 * SaaS-5: operating tenants from the platform panel — the tenant directory, lifecycle actions by a
 * platform administrator (SaaS-3 transitions, attributed and raised as events), the append-only
 * platform audit, and operational events (deduplicated, critical ones mailed, acknowledged).
 */
beforeEach(function (): void {
    $this->world = PlatformWorld::build($this->tenant);
    Filament::setCurrentPanel('platform');
});

test('the tenant directory lists every tenant with its plan and members in a fixed number of queries', function (): void {
    Tenant::factory()->count(3)->create(['name' => 'Listed Tenant']);
    $this->actingAs($this->world->support);

    DB::enableQueryLog();
    Livewire::test(Tenants::class)->assertSee(['Acme Hiring', 'Beta Staffing', 'Listed Tenant', 'Legacy']);
    $few = count(DB::getQueryLog());

    Tenant::factory()->count(5)->create(['name' => 'Listed Tenant']);
    DB::flushQueryLog();
    Livewire::test(Tenants::class)->assertSee('Listed Tenant');

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($few);
});

test('a platform administrator suspends and reactivates a tenant with a reason; attributed, evented; others see no lifecycle action', function (): void {
    $this->actingAs($this->world->administrator);

    Livewire::test(TenantDetail::class, ['tenant' => $this->tenant->id])
        ->callAction('suspend', ['reason' => 'Abuse report #8'])
        ->assertNotified('Suspend: done');

    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and(AuditLog::query()->where('action', 'tenant_suspended')->sole())->actor_kind->toBe('platform')->user_id->toBe($this->world->administrator->id)->reason->toBe('Abuse report #8')
        ->and(PlatformEvent::query()->where('type', 'tenant.suspended')->count())->toBe(1);

    Livewire::test(TenantDetail::class, ['tenant' => $this->tenant->id])->callAction('activate', ['reason' => 'Resolved']);
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Active);

    $this->actingAs($this->world->support);
    Livewire::test(TenantDetail::class, ['tenant' => $this->tenant->id])
        ->assertActionHidden('suspend')
        ->assertActionHidden('transferOwnership')
        ->assertActionHidden('requestDeletion')
        ->assertActionVisible('requestSupport');
});

test('the platform audit shows platform actions across tenants and the platform stream, never a tenant\'s business records', function (): void {
    $this->actingAs($this->world->administrator);
    Livewire::test(TenantDetail::class, ['tenant' => $this->tenant->id])->callAction('suspend', ['reason' => 'Hold']);
    $business = AuditLog::query()->where('auditable_type', Candidate::class)->first() ?? AuditLog::record(Candidate::factory()->create(), 'custom_business', null, null);
    $platform = AuditLog::query()->where('action', 'tenant_suspended')->sole();
    $roleGrant = TenantContext::current()->runWithoutTenant(fn () => AuditLog::query()->withoutTenancy()->where('action', 'platform_role_granted')->first());

    $this->actingAs($this->world->compliance);
    Livewire::test(PlatformAudit::class)->filterTable('action', 'tenant_suspended')->assertCanSeeTableRecords([$platform]);
    Livewire::test(PlatformAudit::class)->filterTable('action', 'platform_role_granted')->assertCanSeeTableRecords([$roleGrant]);
    Livewire::test(PlatformAudit::class)->searchTable($business->action)->assertCanNotSeeTableRecords([$business]);

    Livewire::withQueryParams(['tenant' => $this->world->identity->beta->id])->test(PlatformAudit::class)->assertCanNotSeeTableRecords([$platform]);
});

test('the audit trail is append-only', function (): void {
    $entry = AuditLog::record(Candidate::factory()->create(), 'custom_entry', null, null);

    expect(fn () => $entry->forceFill(['action' => 'rewritten'])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'append-only')
        ->and($entry->fresh()->action)->toBe('custom_entry');
});

test('platform events are recorded once per key; a critical one is mailed to the operations address, with no tenant on the queue', function (): void {
    Mail::fake();
    config(['platform.notify_email' => 'ops@platform.test']);
    $events = app(PlatformEvents::class);

    $first = $events->record('purge.failed', PlatformEventSeverity::Critical, 'Purge failed', $this->tenant, [], 'purge.failed:1:1');
    $again = $events->record('purge.failed', PlatformEventSeverity::Critical, 'Purge failed', $this->tenant, [], 'purge.failed:1:1');
    $events->record('support.requested', PlatformEventSeverity::Info, 'Support requested', $this->tenant);

    expect($again->is($first))->toBeTrue()
        ->and(PlatformEvent::query()->count())->toBe(2);
    Mail::assertQueued(PlatformEventMail::class, 1);
    Mail::assertQueued(PlatformEventMail::class, fn (PlatformEventMail $mail): bool => $mail->hasTo('ops@platform.test') && $mail->eventId === $first->id);

    config(['platform.notify_email' => null]);
    $events->record('purge.failed', PlatformEventSeverity::Critical, 'Another', null, [], 'purge.failed:2:1');
    Mail::assertQueued(PlatformEventMail::class, 1);
});

test('operators acknowledge events; a failed provisioning raises a critical event', function (): void {
    Mail::fake();
    app(PlanCatalogService::class)->sync();
    $failing = new class extends TenantDefaults
    {
        public function apply(): void
        {
            throw new RuntimeException('Reference data unavailable');
        }
    };
    app()->instance(TenantDefaults::class, $failing);

    expect(fn () => app(TenantProvisioningService::class)->provision(new ProvisioningRequest(slug: 'nova', name: 'Nova', ownerEmail: 'owner@nova.test', planCode: 'starter', trialDays: 14)))->toThrow(RuntimeException::class);

    $event = PlatformEvent::query()->where('type', 'provisioning.failed')->sole();
    expect($event->severity)->toBe(PlatformEventSeverity::Critical);

    $this->actingAs($this->world->support);
    Livewire::test(OperationalEvents::class)->assertCanSeeTableRecords([$event])->callTableAction('acknowledge', $event);

    expect($event->fresh()->acknowledged_by)->toBe($this->world->support->id);
    Livewire::test(OperationalEvents::class)->assertCanNotSeeTableRecords([$event]);
});

test('the tenant page\'s ownership, support and export actions reach their services with the form\'s choices', function (): void {
    $successor = User::factory()->create(['email' => 'next@acme.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->actingAs($this->world->everything);

    Livewire::test(TenantDetail::class, ['tenant' => $this->tenant->id])
        ->callAction('transferOwnership', ['new_owner_id' => $successor->id, 'reason' => 'Founder left'])
        ->assertNotified('Ownership transferred')
        ->callAction('requestSupport', ['scopes' => ['audit'], 'minutes' => 30, 'reason' => 'Ticket 77'])
        ->assertNotified('Support access requested')
        ->callAction('requestExport', ['reason' => 'DSAR 3'])
        ->assertNotified('Export requested');

    expect(app(TenantOwnershipService::class)->owner($this->tenant)?->is($successor))->toBeTrue()
        ->and(SupportAccessGrant::query()->sole()->requested_scopes)->toBe(['audit'])
        ->and(ComplianceExport::query()->sole()->reason)->toBe('DSAR 3');
});

test('the panel\'s ownership form refuses a transfer when the owner changed after it opened', function (): void {
    $first = User::factory()->create(['email' => 'first@acme.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $second = User::factory()->create(['email' => 'second@acme.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->actingAs($this->world->administrator);

    $page = Livewire::test(TenantDetail::class, ['tenant' => $this->tenant->id])->mountAction('transferOwnership');

    app(TenantOwnershipService::class)->transfer($this->tenant, $first, 'Meanwhile', $this->world->secondAdministrator);

    $page->fillForm(['new_owner_id' => $second->id, 'reason' => 'Stale'])->callMountedAction()->assertNotified('Not done');

    expect(app(TenantOwnershipService::class)->owner($this->tenant)?->is($first))->toBeTrue();
});
