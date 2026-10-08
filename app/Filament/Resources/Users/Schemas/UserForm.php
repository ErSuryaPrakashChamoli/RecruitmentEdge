<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Identity\AuthorityGuard;
use App\Services\Identity\RoleAssignmentService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 8.4: roles and the employee link are plain option lists — the Create/Edit pages hand them to
 * RoleAssignmentService / IdentityProvisioningService, which enforce every escalation rule (the
 * disabled options here are only a convenience).
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (?User $record): bool => ! self::managesCredentialsOf($record)),
                // SaaS-2 (S1-01): no uniqueness check here — it would answer "already taken" for any
                // address on the platform. CredentialService handles a taken address silently.
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (?User $record): bool => ! self::managesCredentialsOf($record))
                    ->helperText(fn (?User $record): string => self::managesCredentialsOf($record)
                        ? 'A new address is sent a verification link; it applies once the person confirms it.'
                        : self::SHARED_IDENTITY_HINT),
                // Phase 8.4: the staff password policy; the value is hashed by the model and an
                // administrator's change signs the person out everywhere (CredentialService).
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->rule(Password::defaults())
                    ->confirmed()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->visible(fn (?User $record): bool => self::managesCredentialsOf($record))
                    ->helperText('Leave blank to keep the current password. Setting one signs the person out everywhere.'),
                TextInput::make('password_confirmation')
                    ->password()
                    ->revealable()
                    ->requiredWith('password')
                    ->visible(fn (?User $record): bool => self::managesCredentialsOf($record))
                    ->dehydrated(false),
                Select::make('employee_id')
                    ->label('Linked Employee')
                    ->options(fn (?User $record): array => self::employeeOptions($record))
                    ->searchable()
                    ->helperText('Only current employees in your hierarchy without another login are listed.'),
                CheckboxList::make('roles')
                    ->options(fn (): array => Role::query()->forCurrentTenant()->orderBy('name')->pluck('name', 'id')->all())
                    ->disableOptionWhen(fn (string $value): bool => ! self::canGrant((int) $value))
                    ->helperText('You can only grant roles whose permissions you hold; protected roles only by their holders.')
                    ->columns(2)
                    ->required(),
            ]);
    }

    public const string SHARED_IDENTITY_HINT = 'This person also belongs to another organisation, so only they can change their name, email address and password ("Forgot password" on the sign-in page).';

    /**
     * SaaS-2: whether this tenant manages the person's global identity details — name, email,
     * password (they belong to this tenant alone). The services enforce it; the form only follows.
     */
    public static function managesCredentialsOf(?User $record): bool
    {
        return $record !== null && app(AuthorityGuard::class)->managesCredentialsOf($record);
    }

    public static function canGrant(int $roleId): bool
    {
        $actor = auth()->user();
        $role = Role::query()->forCurrentTenant()->with('permissions')->find($roleId);

        return $actor instanceof User && $role !== null && app(RoleAssignmentService::class)->canGrant($actor, $role);
    }

    /**
     * @return array<int, string>
     */
    public static function employeeOptions(?User $record): array
    {
        $actor = auth()->user();
        $visible = $actor instanceof User ? app(HierarchyService::class)->visibleEmployeeIdsFor($actor) : collect();

        return Employee::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $linkable) => $linkable->where('status', EmployeeStatus::Active->value)->whereDoesntHave('user'))
                ->when($record?->employee_id !== null, fn (Builder $current) => $current->orWhere('id', $record->employee_id)))
            ->when($visible !== null, fn (Builder $query) => $query->whereIn('id', $visible))
            ->orderBy('first_name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName().' ('.$employee->employee_code.')'])
            ->all();
    }
}
