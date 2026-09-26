<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Identity\IdentityProvisioningService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Phase 8.4: provisioned through IdentityProvisioningService (roles and employee link checked
     * there).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(IdentityProvisioningService::class)->createStaffUser($data, $actor);
        } catch (DomainException $e) {
            Notification::make()->title('Login could not be created')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
