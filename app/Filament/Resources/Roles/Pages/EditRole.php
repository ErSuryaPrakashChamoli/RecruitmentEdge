<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Role $record) => RoleResource::deleteThroughService($record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();

        return [...$data, 'permissions' => $role->permissions()->pluck('id')->map(fn ($id) => (string) $id)->all()];
    }

    /**
     * Phase 8.4: renamed and re-permissioned by RoleAssignmentService (audited with the old and new
     * name and permission names).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof Role, 403);

        $permissions = array_key_exists('permissions', $data) ? $data['permissions'] : $record->permissions()->pluck('id')->all();

        try {
            return app(RoleAssignmentService::class)->updateRole($record, (string) $data['name'], $permissions, $actor);
        } catch (DomainException $e) {
            Notification::make()->title('Role could not be saved')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
