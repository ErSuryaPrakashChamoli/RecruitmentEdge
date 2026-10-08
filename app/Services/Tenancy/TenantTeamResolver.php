<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * SaaS-1: spatie/laravel-permission teams are tenants. The team id is always the current
 * TenantContext — there is no separate "permissions team" to keep in sync. With no tenant, role
 * and permission checks see no roles at all (fail closed).
 */
class TenantTeamResolver implements PermissionsTeamResolver
{
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        // Spatie's Octane reset passes null; anything else must already be the current tenant.
        if ($id !== null && (int) $id !== TenantContext::current()->id()) {
            throw new LogicException('The permissions team follows the tenant context; use TenantContext::run() to act in another tenant.');
        }
    }

    public function getPermissionsTeamId(): int|string|null
    {
        return TenantContext::current()->id();
    }
}
