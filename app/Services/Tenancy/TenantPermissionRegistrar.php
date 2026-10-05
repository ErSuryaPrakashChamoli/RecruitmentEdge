<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * SaaS-7 (S7-01, closes S1-10): spatie's permission → roles map, one per tenant.
 *
 * Spatie caches every permission with every role that holds it — in a multi-tenant database that
 * is every tenant's roles (App\Models\Role has no tenant scope), and every request and queued job
 * loads and hydrates the whole map. Measured at the production memory_limit (256 MB): about 205 MB
 * to build at 100 tenants, out of memory before 250 (docs/saas-7-discovery.md §10). Here the map
 * holds the permissions (global) with the current tenant's roles only: its size no longer grows
 * with the number of tenants, and a role change rebuilds one tenant's map, not everyone's.
 *
 * Semantics are spatie's: a user's roles are loaded per team (= tenant), so a role of another
 * tenant could never have matched anyway. With no tenant the map carries no roles (fail closed).
 *
 * Cache key: <key>.<global version>.tenant.<id>.<generation>. Invalidation rotates a token instead
 * of deleting an entry — twice: when the change is made and again after its transaction commits.
 * A map rebuilt in between from not-yet-committed state was stored under a generation nobody reads
 * any more, so it can never be served (SaaS-7 race test).
 * - a role, or its permissions, changed: that role's tenant's generation (App\Models\Role);
 * - the permissions table changed, or a change with no tenant: the global version.
 */
class TenantPermissionRegistrar extends PermissionRegistrar
{
    private string $baseKey = '';

    /**
     * Whether a map is held in memory, and for which tenant ("platform": none).
     */
    private bool $loaded = false;

    private int|string $loadedFor = 'platform';

    public function initializeCache(): void
    {
        parent::initializeCache();
        $this->baseKey = (string) config('permission.cache.key');
    }

    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        $tenantId = TenantContext::current()->id();

        // A process that changed tenant (a worker between jobs, TenantContext::run) reloads; within
        // one tenant the key is read once.
        if (! $this->loaded || $this->loadedFor !== ($tenantId ?? 'platform')) {
            parent::clearPermissionsCollection();
            $this->cacheKey = $this->keyFor($tenantId);
            $this->loaded = true;
            $this->loadedFor = $tenantId ?? 'platform';
        }

        return parent::getPermissions($params, $onlyOne);
    }

    public function clearPermissionsCollection(): void
    {
        parent::clearPermissionsCollection();
        $this->loaded = false;
    }

    /**
     * Called by spatie after any permission or role change it makes. The tenant is the current
     * one; with none (the global permissions table, a platform seeder) every tenant's map goes.
     */
    public function forgetCachedPermissions(): bool
    {
        $tenantId = TenantContext::current()->id();

        $tenantId === null ? $this->forgetAllTenants() : $this->forgetTenant($tenantId);

        return true;
    }

    /**
     * A change to one tenant's roles or their permissions.
     */
    public function forgetTenant(int $tenantId): void
    {
        $rotate = function () use ($tenantId): void {
            $this->cache->forever($this->generationKey($tenantId), Str::random(12));
            $this->clearPermissionsCollection();
        };

        $rotate();
        DB::afterCommit($rotate);
    }

    /**
     * A change to the permissions themselves: every tenant's map is rebuilt on its next read.
     */
    public function forgetAllTenants(): void
    {
        $rotate = function (): void {
            $this->cache->forever($this->baseKey.'.version', Str::random(12));
            $this->clearPermissionsCollection();
        };

        $rotate();
        DB::afterCommit($rotate);
    }

    /**
     * The current tenant's key (for tests and diagnostics).
     */
    public function keyFor(?int $tenantId): string
    {
        $version = (string) $this->cache->rememberForever($this->baseKey.'.version', fn (): string => Str::random(12));

        if ($tenantId === null) {
            return "{$this->baseKey}.{$version}.platform";
        }

        $generation = (string) $this->cache->rememberForever($this->generationKey($tenantId), fn (): string => Str::random(12));

        return "{$this->baseKey}.{$version}.tenant.{$tenantId}.{$generation}";
    }

    /**
     * The permissions, each with the roles of the current tenant only.
     */
    protected function getPermissionsWithRoles(): Collection
    {
        $tenantId = TenantContext::current()->id();

        return $this->permissionClass::select()->with(['roles' => function ($roles) use ($tenantId): void {
            $tenantId === null
                ? $roles->whereRaw('1 = 0')
                : $roles->where($roles->qualifyColumn($this->teamsKey), $tenantId);
        }])->get();
    }

    /**
     * Where a tenant's generation token lives in the shared cache (another process rotates it).
     */
    public function generationKey(int $tenantId): string
    {
        return "{$this->baseKey}.tenant.{$tenantId}.generation";
    }
}
