<?php

use App\Enums\AccessState;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\StaffAccessService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-2: one global identity, one membership per tenant. The membership — not the identity —
 * holds the person's access state and employee record in that tenant, so one tenant's decision
 * never changes the person's access to another tenant.
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
});

function membershipStatusIn(IdentityWorld $world, User $user, string $tenant): AccessState
{
    return TenantMembership::query()->where('tenant_id', $world->{$tenant}->id)->where('user_id', $user->id)->sole()->status;
}

test('the identity exists once; its employee record and access state are those of the current tenant', function (): void {
    $person = $this->world->personA;

    expect(User::query()->where('email', 'asha@example.test')->count())->toBe(1)
        ->and($person->fresh()->employee_id)->toBe($this->world->personAInAcme->id)
        ->and($person->fresh()->employee->is($this->world->personAInAcme))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->fresh()->employee_id))->toBe($this->world->personAInBeta->id)
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->fresh()->employee->is($this->world->personAInBeta)))->toBeTrue()
        ->and(TenantContext::current()->runWithoutTenant(fn () => $person->fresh()->employee_id))->toBeNull()
        ->and(TenantContext::current()->runWithoutTenant(fn () => $person->fresh()->access_status))->toBeNull()
        ->and($this->world->personB->fresh()->access_status)->toBe(AccessState::Active)
        ->and(TenantContext::current()->run($this->world->beta, fn () => $this->world->personB->fresh()->access_status))->toBeNull();
});

test('each employee record has at most one login, and finds it through the membership', function (): void {
    expect($this->world->personAInAcme->user->is($this->world->personA))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->beta, fn () => $this->world->personAInBeta->fresh()->user->is($this->world->personA)))->toBeTrue()
        ->and(fn () => TenantMembership::query()->create(['tenant_id' => $this->world->acme->id, 'user_id' => $this->world->personD->id, 'employee_id' => $this->world->personAInAcme->id, 'status' => AccessState::Active]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the identity never carries an employee link or access state of its own', function (): void {
    expect(fn () => $this->world->personB->update(['employee_id' => $this->world->personAInAcme->id]))->toThrow(LogicException::class, 'IdentityProvisioningService')
        ->and(fn () => $this->world->personB->forceFill(['access_status' => AccessState::Revoked])->save())->toThrow(LogicException::class, 'StaffAccessService')
        ->and(fn () => TenantMembership::query()->where('user_id', $this->world->personB->id)->sole()->update(['status' => AccessState::Revoked]))->toThrow(LogicException::class, 'StaffAccessService');
});

test('suspending a membership closes that tenant only: the person keeps working in their other tenant', function (): void {
    $person = $this->world->personA;
    $epoch = $person->fresh()->session_epoch;

    app(StaffAccessService::class)->suspend($person, $this->world->adminA, 'Investigation');

    expect(membershipStatusIn($this->world, $person, 'acme'))->toBe(AccessState::Suspended)
        ->and(membershipStatusIn($this->world, $person, 'beta'))->toBe(AccessState::Active)
        ->and($person->fresh()->canAccessTenant($this->world->acme))->toBeFalse()
        ->and($person->fresh()->canAccessTenant($this->world->beta))->toBeTrue()
        ->and($person->fresh()->session_epoch)->toBe($epoch)
        ->and(AuditLog::query()->where('action', 'access_suspended')->where('auditable_id', $person->id)->pluck('tenant_id')->all())->toBe([$this->world->acme->id])
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'access_suspended')->count()))->toBe(0);

    $this->actingAs($person->fresh());

    $this->get('/admin/acme')->assertNotFound();
    $this->get('/admin/beta')->assertOk();
});

test('revoking a membership removes that tenant\'s roles only; restoring it grants the base role there only', function (): void {
    $person = $this->world->personA;

    app(StaffAccessService::class)->revoke($person, $this->world->adminA, 'Left the Acme project');

    $acmeMembership = TenantMembership::query()->where('tenant_id', $this->world->acme->id)->where('user_id', $person->id)->sole();

    expect($acmeMembership->status)->toBe(AccessState::Revoked)
        ->and($acmeMembership->revoked_roles)->toBe(['manager'])
        ->and($person->fresh()->roles()->count())->toBe(0)
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->fresh()->hasRole('recruiter')))->toBeTrue();

    app(StaffAccessService::class)->restore($person, $this->world->adminA, 'Back on the project');

    expect($person->fresh()->getRoleNames()->all())->toBe(['employee'])
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->fresh()->getRoleNames()->all()))->toBe(['recruiter']);
});

test('a tenant can never change the access of a person who is not its member', function (): void {
    TenantContext::current()->run($this->world->beta, function (): void {
        expect(fn () => app(StaffAccessService::class)->suspend($this->world->personB, $this->world->adminB, 'Not ours'))->toThrow(DomainException::class)
            ->and(fn () => app(StaffAccessService::class)->revoke($this->world->personB, null, 'Not ours', 'employment'))->toThrow(DomainException::class, 'not a member');
    });

    expect(membershipStatusIn($this->world, $this->world->personB, 'acme'))->toBe(AccessState::Active);
});

test('when the last usable membership closes, every session of the identity ends', function (): void {
    $person = $this->world->personB;
    $epoch = $person->fresh()->session_epoch;

    app(StaffAccessService::class)->suspend($person, $this->world->adminA, 'Investigation');

    expect($person->fresh()->session_epoch)->toBe($epoch + 1)
        ->and(AuditLog::query()->where('action', 'sessions_revoked')->where('auditable_id', $person->id)->exists())->toBeTrue()
        ->and($person->fresh()->accessibleTenants())->toBeEmpty();
});

test('a separation in one tenant ends access there only, before and after it is applied', function (): void {
    $person = $this->world->personA;

    TenantContext::current()->run($this->world->beta, fn () => lifecycleFixture(fn () => EmployeeSeparation::factory()->create(['employee_id' => $this->world->personAInBeta->id, 'separation_date' => now()->subDays(2)->toDateString()])));

    // Due but not applied: the gate already refuses Beta, never Acme.
    expect($person->fresh()->canAccessTenant($this->world->beta))->toBeFalse()
        ->and($person->fresh()->canAccessTenant($this->world->acme))->toBeTrue();

    TenantContext::current()->run($this->world->beta, fn () => app(EmployeeLifecycleService::class)->enforceDueSeparations());

    expect(membershipStatusIn($this->world, $person, 'beta'))->toBe(AccessState::Revoked)
        ->and(membershipStatusIn($this->world, $person, 'acme'))->toBe(AccessState::Active)
        ->and($person->fresh()->hasRole('manager'))->toBeTrue();
});

test('inactive employment in one tenant suspends that tenant\'s membership only', function (): void {
    TenantContext::current()->run($this->world->beta, fn () => app(EmployeeLifecycleService::class)->deactivate($this->world->personAInBeta, $this->world->adminB, 'Long leave'));

    expect(membershipStatusIn($this->world, $this->world->personA, 'beta'))->toBe(AccessState::Suspended)
        ->and(membershipStatusIn($this->world, $this->world->personA, 'acme'))->toBe(AccessState::Active);
});

test('a dashboard stand-in for an employee holds the employee and no access at all', function (): void {
    $employee = Employee::factory()->create();
    $standIn = User::standInFor($employee);

    expect($standIn->exists)->toBeFalse()
        ->and($standIn->employee_id)->toBe($employee->id)
        ->and($standIn->access_status)->toBeNull()
        ->and($standIn->can('candidates.view'))->toBeFalse()
        ->and($standIn->accessibleTenants())->toBeEmpty();
});
