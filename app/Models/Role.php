<?php

namespace App\Models;

use DomainException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Phase 8.4: the application's role model (config permission.models.role). A role is identified by
 * its immutable `key`, never by its editable display name: once set, the key cannot change, and a
 * protected role (CHRO) cannot be deleted — renaming or recreating a role by name never bypasses
 * that. Role and permission assignment goes through RoleAssignmentService.
 */
class Role extends SpatieRole
{
    protected static function booted(): void
    {
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
    }

    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
        ];
    }

    public static function byKey(string $key): ?self
    {
        return self::query()->where('key', $key)->first();
    }

    public static function byKeyOrFail(string $key): self
    {
        return self::byKey($key) ?? throw new DomainException("The {$key} role does not exist.");
    }
}
