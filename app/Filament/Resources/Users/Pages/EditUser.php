<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\UserAccessActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Identity\AuthorityGuard;
use App\Services\Identity\CredentialService;
use App\Services\Identity\IdentityProvisioningService;
use App\Services\Identity\RoleAssignmentService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Phase 8.4: logins are revoked, never deleted (history and attribution stay intact).
     */
    protected function getHeaderActions(): array
    {
        return UserAccessActions::all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        return [...$data, 'roles' => $user->roles()->pluck('roles.id')->map(fn ($id) => (string) $id)->all()];
    }

    /**
     * Phase 8.4: roles through RoleAssignmentService and the employee link through
     * IdentityProvisioningService — never a direct relationship sync.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof User, 403);

        $roles = Arr::pull($data, 'roles', []);
        $employeeId = Arr::pull($data, 'employee_id');
        $password = Arr::pull($data, 'password');
        $email = Arr::pull($data, 'email');

        try {
            DB::transaction(function () use ($record, $data, $roles, $employeeId, $password, $email, $actor): void {
                app(IdentityProvisioningService::class)->linkEmployee($record, filled($employeeId) ? (int) $employeeId : null, $actor);
                app(RoleAssignmentService::class)->syncUserRoles($record, $roles, $actor);

                // SaaS-2: the name is the identity's own, shown in every tenant it belongs to — changed
                // here only for an identity this tenant alone holds (the field is read-only otherwise).
                if (app(AuthorityGuard::class)->managesCredentialsOf($record)) {
                    $record->update(Arr::only($data, ['name']));
                }

                if (filled($password)) {
                    app(CredentialService::class)->setPasswordByAdministrator($record, $password, $actor);
                }

                if (filled($email)) {
                    app(CredentialService::class)->requestEmailChange($record, $email, $actor);
                }
            });
        } catch (DomainException $e) {
            Notification::make()->title('Login could not be updated')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        return $record;
    }
}
