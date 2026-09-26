<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Identity\RoleAssignmentService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

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
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Leave blank to keep the current password.'),
                Select::make('employee_id')
                    ->label('Linked Employee')
                    ->options(fn (?User $record): array => self::employeeOptions($record))
                    ->searchable()
                    ->helperText('Only current employees in your hierarchy without another login are listed.'),
                CheckboxList::make('roles')
                    ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->disableOptionWhen(fn (string $value): bool => ! self::canGrant((int) $value))
                    ->helperText('You can only grant roles whose permissions you hold; protected roles only by their holders.')
                    ->columns(2)
                    ->required(),
            ]);
    }

    private static function canGrant(int $roleId): bool
    {
        $actor = auth()->user();
        $role = Role::query()->with('permissions')->find($roleId);

        return $actor instanceof User && $role !== null && app(RoleAssignmentService::class)->canGrant($actor, $role);
    }

    /**
     * @return array<int, string>
     */
    private static function employeeOptions(?User $record): array
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
