<?php

use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantPermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-7 (S7-01, S1-10): spatie's permission → roles map is cached per tenant. The global map held
 * every tenant's roles and ran out of memory at 256 MB before 250 tenants; this one holds the
 * current tenant's roles only, a role change rebuilds only its own tenant's map, and decisions are
 * exactly spatie's.
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
    $this->registrar = app(PermissionRegistrar::class);
});

/**
 * @return list<int> the tenant ids of every role in the in-memory map
 */
function permissionCacheRoleTenants(): array
{
    return app(PermissionRegistrar::class)->getPermissions()->flatMap->roles->pluck('tenant_id')->unique()->values()->all();
}

function permissionCacheCheck(User $user, string $permission): bool
{
    app(PermissionRegistrar::class)->clearPermissionsCollection();

    return $user->fresh()->hasPermissionTo($permission);
}

test('the registrar is the tenant-scoped one, and a tenant\'s map holds its own roles only', function (): void {
    expect($this->registrar)->toBeInstanceOf(TenantPermissionRegistrar::class)
        ->and(TenantContext::current()->run($this->world->acme, fn () => permissionCacheRoleTenants()))->toBe([$this->world->acme->id])
        ->and(TenantContext::current()->run($this->world->beta, fn () => permissionCacheRoleTenants()))->toBe([$this->world->beta->id])
        ->and(TenantContext::current()->runWithoutTenant(fn () => permissionCacheRoleTenants()))->toBe([]);
});

test('decisions are unchanged: a permission granted to a tenant\'s role answers in that tenant only', function (): void {
    $personA = $this->world->personA;
    TenantContext::current()->run($this->world->beta, fn () => Role::byKeyOrFail('recruiter')->givePermissionTo('audit.view'));

    expect(TenantContext::current()->run($this->world->beta, fn () => permissionCacheCheck($personA, 'audit.view')))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->acme, fn () => Role::byKeyOrFail('manager')->hasPermissionTo('audit.view')))->toBeFalse()
        ->and(TenantContext::current()->run($this->world->acme, fn () => permissionCacheCheck($personA, 'audit.view')))->toBeFalse();
});

test('one process switching tenants answers for the tenant it is in each time', function (): void {
    $personA = $this->world->personA;
    TenantContext::current()->run($this->world->beta, fn () => Role::byKeyOrFail('recruiter')->givePermissionTo('audit.view'));
    $answers = [];

    foreach ([$this->world->acme, $this->world->beta, $this->world->acme, $this->world->beta] as $tenant) {
        // No clearing between switches: the in-memory map must follow the tenant on its own.
        $answers[] = TenantContext::current()->run($tenant, fn (): bool => $personA->fresh()->hasPermissionTo('audit.view'));
    }

    expect($answers)->toBe([false, true, false, true]);
});

test('a role change rebuilds its own tenant\'s map only — also when made from another tenant\'s context', function (): void {
    $acmeKey = fn (): string => $this->registrar->keyFor($this->world->acme->id);
    $betaKey = fn (): string => $this->registrar->keyFor($this->world->beta->id);
    [$acmeBefore, $betaBefore] = [$acmeKey(), $betaKey()];
    $betaRecruiter = TenantContext::current()->run($this->world->beta, fn () => Role::byKeyOrFail('recruiter'));

    // Running in acme, change beta's role (a platform-style path): beta's map goes, acme's stays.
    TenantContext::current()->run($this->world->acme, fn () => $betaRecruiter->givePermissionTo('audit.view'));

    expect($acmeKey())->toBe($acmeBefore)
        ->and($betaKey())->not->toBe($betaBefore)
        ->and(TenantContext::current()->run($this->world->beta, fn () => permissionCacheCheck($this->world->personA, 'audit.view')))->toBeTrue();

    $betaAfterGrant = $betaKey();
    TenantContext::current()->run($this->world->acme, fn () => $betaRecruiter->update(['name' => 'Recruiter (renamed)']));

    expect($betaKey())->not->toBe($betaAfterGrant)
        ->and($acmeKey())->toBe($acmeBefore);
});

test('a new permission (the global table) reaches every tenant\'s map', function (): void {
    $before = [$this->registrar->keyFor($this->world->acme->id), $this->registrar->keyFor($this->world->beta->id)];

    // Seeders create permissions inside a tenant; every tenant must see them.
    TenantContext::current()->run($this->world->acme, fn () => Permission::create(['name' => 'saas7.new', 'guard_name' => 'web']));
    TenantContext::current()->run($this->world->beta, fn () => Role::byKeyOrFail('recruiter')->givePermissionTo('saas7.new'));

    expect([$this->registrar->keyFor($this->world->acme->id), $this->registrar->keyFor($this->world->beta->id)])->each->not->toBeIn($before)
        ->and(TenantContext::current()->run($this->world->beta, fn () => permissionCacheCheck($this->world->personA, 'saas7.new')))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->acme, fn () => permissionCacheCheck($this->world->personA, 'saas7.new')))->toBeFalse();
});

test('with no tenant the map carries no roles, so nobody holds a permission (fail closed)', function (): void {
    $chief = $this->world->adminA;

    expect(TenantContext::current()->run($this->world->acme, fn () => permissionCacheCheck($chief, 'audit.view')))->toBeTrue()
        ->and(TenantContext::current()->runWithoutTenant(fn () => app(PermissionRegistrar::class)->getPermissions(['name' => 'audit.view'])->first()->roles))->toHaveCount(0);
});
