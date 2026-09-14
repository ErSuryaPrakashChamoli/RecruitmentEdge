<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\Concerns\AuditsRolePermissions;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateRole extends CreateRecord
{
    use AuditsRolePermissions;

    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();

        $this->auditRolePermissions($role, 'created', null, []);
    }
}
