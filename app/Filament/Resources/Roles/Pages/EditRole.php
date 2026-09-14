<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\Concerns\AuditsRolePermissions;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    use AuditsRolePermissions;

    protected static string $resource = RoleResource::class;

    protected ?string $roleNameBeforeSave = null;

    /**
     * @var array<int, string>
     */
    protected array $permissionNamesBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Captured before validation, because the permissions CheckboxList relationship is synced while
     * the form state is read — before the beforeSave hook runs.
     */
    protected function beforeValidate(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();

        $this->roleNameBeforeSave = $role->getRawOriginal('name');
        $this->permissionNamesBeforeSave = $this->currentPermissionNames($role);
    }

    protected function afterSave(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();

        $this->auditRolePermissions($role, 'permissions_updated', $this->roleNameBeforeSave, $this->permissionNamesBeforeSave);
    }
}
