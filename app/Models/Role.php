<?php

namespace App\Models;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantPermissionRegistrar;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 8.4: the application's role model (config permission.models.role). A role is identified by
 * its immutable `key`, never by its editable display name: once set, the key cannot change, and a
 * protected role (CHRO) cannot be deleted — renaming or recreating a role by name never bypasses
 * that. Role and permission assignment goes through RoleAssignmentService.
 */
/**
 * SaaS-1: every role belongs to one tenant (tenant_id is the spatie team key). Role checks
 * (hasRole, can, User::role()) are already limited to the current tenant by spatie teams; direct
 * role queries must say so with forCurrentTenant(). Not a global scope on purpose: spatie caches
 * the permission → roles map for all tenants together and must see every role to build it.
 */
class Role extends SpatieRole
{
    /**
     * Whether roles carry tenant_id yet: the Phase 4–8.5 permission-grant migrations run (on an
     * upgrade) before the SaaS-1 migrations, against the single organisation's roles. Only a
     * positive answer is remembered, so the same process sees the column once it is added.
     */
    private static bool $hasTenantColumn = false;

    protected static function booted(): void
    {
        // SaaS-1: a role is created in the current tenant (spatie's static create() does the same;
        // this also covers Role::query()->create()). No tenant, no role.
        static::creating(function (self $role): void {
            if (self::$hasTenantColumn || Schema::hasColumn('roles', 'tenant_id')) {
                $role->tenant_id ??= TenantContext::current()->requireId();
            }
        });

        static::updating(function (self $role): void {
            if ($role->isDirty('key') && $role->getOriginal('key') !== null) {
                throw new DomainException('A role key cannot be changed once set.');
            }

            if ($role->isDirty('is_protected') && (bool) $role->getOriginal('is_protected')) {
                throw new DomainException('A protected role cannot be unprotected.');
            }
        });

        static::deleting(function (self $role): void {
            if ($role->is_protected) {
                throw new DomainException("The {$role->name} role is protected and cannot be deleted.");
            }
        });

        // SaaS-7 (S7-01): the permission map is cached per tenant — a role's change rebuilds its own
        // tenant's map, whatever tenant the code runs in.
        static::saved(function (self $role): void {
            $role->forgetCachedPermissions();
        });
        static::deleted(function (self $role): void {
            $role->forgetCachedPermissions();
        });
    }

    /**
     * SaaS-7 (S7-01): replaces spatie's listener, which invalidates the tenant the code happens to
     * run in; booted() above invalidates the role's own tenant instead.
     */
    public static function bootRefreshesPermissionCache(): void {}

    /**
     * Spatie calls this after giving or revoking a role's permissions.
     */
    public function forgetCachedPermissions(): void
    {
        $registrar = app(PermissionRegistrar::class);

        if ($registrar instanceof TenantPermissionRegistrar && $this->tenant_id !== null) {
            $registrar->forgetTenant((int) $this->tenant_id);

            return;
        }

        $registrar->forgetCachedPermissions();
    }

    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
        ];
    }

    /**
     * Filament's native tenancy scopes the Roles resource through this relationship.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function byKey(string $key): ?self
    {
        return self::query()->forCurrentTenant()->where('key', $key)->first();
    }

    /**
     * Limits a role query to the current tenant; with no tenant it refuses (MissingTenantContext).
     */
    #[Scope]
    protected function forCurrentTenant(Builder $query): void
    {
        if (! self::$hasTenantColumn && ! (self::$hasTenantColumn = Schema::hasColumn('roles', 'tenant_id'))) {
            return;
        }

        $query->where($query->qualifyColumn('tenant_id'), TenantContext::current()->requireId());
    }

    public static function byKeyOrFail(string $key): self
    {
        return self::byKey($key) ?? throw new DomainException("The {$key} role does not exist.");
    }
}
