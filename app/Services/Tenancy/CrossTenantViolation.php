<?php

namespace App\Services\Tenancy;

use LogicException;

/**
 * SaaS-1: a write tried to create, move or link a tenant-owned row across tenants. Always refused;
 * the message names tables and ids only.
 */
class CrossTenantViolation extends LogicException
{
    public static function creating(string $model, int $recordTenant, int $contextTenant): self
    {
        return new self(class_basename($model)." belongs to tenant {$recordTenant} but the current tenant is {$contextTenant}.");
    }

    public static function moving(string $model): self
    {
        return new self(class_basename($model).' cannot be moved to another tenant.');
    }

    public static function reference(string $model, string $column, string $parentTable, mixed $parentId): self
    {
        return new self(class_basename($model).".{$column} refers to {$parentTable} #{$parentId}, which is not in the same tenant.");
    }
}
