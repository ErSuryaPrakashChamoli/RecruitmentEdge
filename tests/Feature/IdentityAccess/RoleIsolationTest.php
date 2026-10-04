<?php

use App\Models\Candidate;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Tenancy\TenantContext;
use Spatie\Permission\Models\Permission;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-2: roles and permissions are the tenant's (spatie teams). A role granted in Tenant A grants
 * nothing in Tenant B — not through the database, and not through anything already loaded or cached
 * in the process when the same identity is asked about another tenant.
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
});

test('Tenant A\'s role never answers in Tenant B, even on the same loaded identity', function (): void {
    $person = $this->world->personA;

    // Loaded and checked in Acme first (manager), then asked in Beta on the very same object.
    expect($person->hasRole('manager'))->toBeTrue()
        ->and($person->can('automation.view'))->toBeTrue()
        ->and($person->relationLoaded('roles'))->toBeTrue();

    TenantContext::current()->run($this->world->beta, function () use ($person): void {
        expect($person->hasRole('manager'))->toBeFalse()
            ->and($person->hasRole('recruiter'))->toBeTrue()
            ->and($person->can('automation.view'))->toBeFalse()
            ->and($person->getRoleNames()->all())->toBe(['recruiter'])
            ->and($person->getAllPermissions()->pluck('name'))->not->toContain('automation.view');
    });

    // And back in Acme, Beta's answer never sticks either.
    expect($person->getRoleNames()->all())->toBe(['manager'])
        ->and($person->can('automation.view'))->toBeTrue();
});

test('the same holds for direct permissions and for the employee record loaded in another tenant', function (): void {
    $person = $this->world->personA;
    TenantContext::current()->run($this->world->beta, fn () => $person->givePermissionTo(Permission::findByName('hierarchy.view-all')));

    expect($person->employee->is($this->world->personAInAcme))->toBeTrue()
        ->and($person->can('hierarchy.view-all'))->toBeFalse()
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->employee->is($this->world->personAInBeta)))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->can('hierarchy.view-all')))->toBeTrue()
        ->and($person->can('hierarchy.view-all'))->toBeFalse();
});

test('a role is granted only in the tenant it belongs to; another tenant\'s role id resolves to nothing', function (): void {
    $betaManager = TenantContext::current()->run($this->world->beta, fn () => Role::byKeyOrFail('manager'));

    app(RoleAssignmentService::class)->syncUserRoles($this->world->personB, [$betaManager->id, Role::byKeyOrFail('recruiter')->id], $this->world->adminA);

    expect($this->world->personB->fresh()->getRoleNames()->all())->toBe(['recruiter'])
        ->and(TenantContext::current()->run($this->world->beta, fn () => User::query()->find($this->world->personB->id)->roles()->count()))->toBe(0);
});

test('policies follow the tenant\'s role: manager-only work is allowed in Acme and refused in Beta', function (): void {
    $acmeCandidate = Candidate::factory()->create();
    $betaCandidate = TenantContext::current()->run($this->world->beta, fn () => Candidate::factory()->create());
    $person = $this->world->personA;

    expect($person->can('candidates.reassign'))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->can('candidates.reassign')))->toBeFalse()
        ->and($person->can('view', $betaCandidate))->toBeFalse()
        ->and(TenantContext::current()->run($this->world->beta, fn () => $person->can('update', $acmeCandidate)))->toBeFalse();
});

test('changing a role in one tenant leaves the other tenant\'s role and its holders unchanged', function (): void {
    $acmeRecruiter = Role::byKeyOrFail('recruiter');
    app(RoleAssignmentService::class)->updateRole($acmeRecruiter, 'recruiter', [Permission::findByName('candidates.viewAny')->id], $this->world->adminA);

    expect($this->world->personB->fresh()->can('candidates.create'))->toBeFalse()
        ->and(TenantContext::current()->run($this->world->beta, fn () => $this->world->personE->fresh()->can('candidates.create')))->toBeTrue();
});
