<?php

namespace App\Filament\Resources\Roles\Concerns;

use App\Models\AuditLog;
use Spatie\Permission\Models\Role;

/**
 * Role permission changes made through the Roles resource are written to the audit trail
 * explicitly: Spatie's Role model can't use the Auditable trait, and permission assignments live in
 * a pivot table that fires no model events.
 */
trait AuditsRolePermissions
{
    /**
     * @return array<int, string>
     */
    protected function currentPermissionNames(Role $role): array
    {
        return $role->permissions()->orderBy('name')->pluck('name')->all();
    }

    /**
     * @param  array<int, string>  $oldPermissions
     */
    protected function auditRolePermissions(Role $role, string $action, ?string $oldName, array $oldPermissions): void
    {
        $newPermissions = $this->currentPermissionNames($role);
        $oldValues = [];
        $newValues = [];

        if ($oldName !== $role->name) {
            $oldValues['name'] = $oldName;
            $newValues['name'] = $role->name;
        }

        if ($oldPermissions !== $newPermissions) {
            $oldValues['permissions'] = $oldPermissions;
            $newValues['permissions'] = $newPermissions;
        }

        if ($newValues === []) {
            return;
        }

        AuditLog::record($role, $action, $action === 'created' ? null : $oldValues, $newValues);
    }
}
