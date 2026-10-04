<?php

namespace App\Services\Tenancy;

use LogicException;

/**
 * SaaS-1: tenant-owned data was touched with no tenant established. This is a programming or
 * routing error, never a user error: the work is refused (fail closed) instead of reading or
 * writing across tenants.
 */
class MissingTenantContext extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("No tenant context is established for {$operation}. Tenant-owned data is never read or written without one.");
    }

    public static function forModel(string $model): self
    {
        return self::forOperation('a '.class_basename($model).' query');
    }
}
