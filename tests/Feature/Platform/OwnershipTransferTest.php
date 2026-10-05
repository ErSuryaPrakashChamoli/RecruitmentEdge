<?php

use App\Enums\AccessState;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PlatformEvent;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Platform\TenantOwnershipService;
use App\Services\Tenancy\TenantContext;
use Tests\Feature\Platform\PlatformWorld;

/*
 * SaaS-5: ownership moves atomically through the existing is_owner flag, to an active member who
 * already holds the CHRO role, by a platform administrator — audited in the tenant's stream with
 * the operator and reason, refused for a closed tenant or when the owner changed meanwhile.
 */
beforeEach(function (): void {
    $this->world = PlatformWorld::build($this->tenant);
    $this->ownership = app(TenantOwnershipService::class);
    $this->successor = User::factory()->create(['name' => 'Next Chief', 'email' => 'next@acme.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
});

function ownersOf(int $tenantId): array
{
    return TenantMembership::query()->where('tenant_id', $tenantId)->where('is_owner', true)->pluck('user_id')->all();
}

test('a platform administrator transfers ownership to an active CHRO member, atomically and audited', function (): void {
    $membership = $this->ownership->transfer($this->tenant, $this->successor, 'Founder left', $this->world->administrator, $this->world->identity->adminA);

    expect($membership->user_id)->toBe($this->successor->id)
        ->and(ownersOf($this->tenant->id))->toBe([$this->successor->id])
        ->and($this->ownership->owner($this->tenant)?->is($this->successor))->toBeTrue()
        ->and($this->world->identity->adminA->fresh()->hasRole('chro'))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'ownership_transferred')->sole();
    expect($audit->actor_kind)->toBe('platform')
        ->and($audit->user_id)->toBe($this->world->administrator->id)
        ->and($audit->reason)->toBe('Founder left')
        ->and($audit->old_values)->toBe(['owner_user_id' => $this->world->identity->adminA->id])
        ->and(PlatformEvent::query()->where('type', 'tenant.ownership_transferred')->count())->toBe(1);
});

test('ownership goes only to an active member who already holds the CHRO role', function (): void {
    $suspended = User::factory()->create(['email' => 'paused@acme.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    TenantMembership::query()->where('tenant_id', $this->tenant->id)->where('user_id', $suspended->id)->update(['status' => AccessState::Suspended->value]);

    expect(fn () => $this->ownership->transfer($this->tenant, $this->world->identity->personA, 'x', $this->world->administrator))->toThrow(DomainException::class, 'CHRO')
        ->and(fn () => $this->ownership->transfer($this->tenant, $suspended, 'x', $this->world->administrator))->toThrow(DomainException::class, 'active member')
        ->and(fn () => $this->ownership->transfer($this->tenant, $this->world->identity->adminB, 'x', $this->world->administrator))->toThrow(DomainException::class, 'active member')
        ->and(fn () => $this->ownership->transfer($this->tenant, $this->successor, ' ', $this->world->administrator))->toThrow(DomainException::class, 'reason')
        ->and(ownersOf($this->tenant->id))->toBe([$this->world->identity->adminA->id]);
});

test('only a platform administrator transfers, never a tenant administrator or another operator', function (): void {
    foreach ([$this->world->support, $this->world->compliance, $this->world->identity->adminA] as $actor) {
        expect(fn () => $this->ownership->transfer($this->tenant, $this->successor, 'x', $actor))->toThrow(DomainException::class, 'platform.tenants.manage');
    }

    expect(ownersOf($this->tenant->id))->toBe([$this->world->identity->adminA->id]);
});

test('a transfer decided on a stale view is refused when the owner changed meanwhile', function (): void {
    expect(fn () => $this->ownership->transfer($this->tenant, $this->successor, 'x', $this->world->administrator, $this->world->identity->personB))
        ->toThrow(DomainException::class, 'owner changed meanwhile');
});

test('a closed tenant\'s ownership does not change', function (TenantStatus $status): void {
    $this->tenant->forceFill(['status' => $status])->save();

    expect(fn () => $this->ownership->transfer($this->tenant->fresh(), $this->successor, 'x', $this->world->administrator))->toThrow(DomainException::class, 'cannot change');
})->with([TenantStatus::Cancelled, TenantStatus::DeletionPending, TenantStatus::Deleted]);

test('the first owner of a tenant without one is assigned, and the console shows and transfers ownership', function (): void {
    TenantMembership::query()->where('tenant_id', $this->tenant->id)->update(['is_owner' => null]);

    $this->artisan('tenants:owner acme')->expectsOutputToContain('no owner recorded')->assertSuccessful();
    $this->artisan('tenants:owner acme next@acme.test --operator=admin@platform.test --reason="Assign"')->assertSuccessful();
    $this->artisan('tenants:owner acme chief@acme.test --operator=support@platform.test --reason="Try"')->assertFailed();

    expect(ownersOf($this->tenant->id))->toBe([$this->successor->id])
        ->and(TenantContext::current()->run($this->tenant, fn () => AuditLog::query()->where('action', 'ownership_assigned')->count()))->toBe(1);
});
