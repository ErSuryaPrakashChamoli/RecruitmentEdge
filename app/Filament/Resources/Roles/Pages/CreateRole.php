<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Phase 8.4: created and audited by RoleAssignmentService.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(RoleAssignmentService::class)->createRole((string) $data['name'], $data['permissions'] ?? [], $actor);
        } catch (DomainException $e) {
            Notification::make()->title('Role could not be created')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
