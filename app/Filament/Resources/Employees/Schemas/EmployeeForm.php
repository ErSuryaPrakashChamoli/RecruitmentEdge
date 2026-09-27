<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\EmployeeStatus;
use App\Filament\Support\ActiveMasterDataOptions;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('employee_code')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('mobile')
                    ->tel()
                    ->maxLength(20),
                Select::make('department_id')
                    ->relationship('department', 'name', ActiveMasterDataOptions::scope('department_id'))
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('designation_id')
                    ->relationship('designation', 'name', ActiveMasterDataOptions::scope('designation_id'))
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('location_id')
                    ->relationship('location', 'name', ActiveMasterDataOptions::scope('location_id'))
                    ->searchable()
                    ->preload(),
                // Phase 8.4: a plain option list — the page hands the choice to
                // HierarchyIntegrityService; a relationship field would write it directly.
                Select::make('reports_to_id')
                    ->label('Reports To')
                    ->options(fn (?Employee $record): array => self::assignableManagers(Employee::query(), $record)
                        ->orderBy('first_name')
                        ->limit(500)
                        ->get()
                        ->when($record?->reportsTo !== null, fn ($managers) => $managers->push($record->reportsTo)->unique('id'))
                        ->mapWithKeys(fn (Employee $manager) => [$manager->id => $manager->fullName().' ('.$manager->employee_code.')'])
                        ->all())
                    ->helperText('Current employees in your hierarchy; nobody in this employee\'s own reporting line can be chosen.')
                    ->searchable(),
                DatePicker::make('date_of_joining'),
                Select::make('status')
                    ->options(self::statusOptions())
                    ->default(EmployeeStatus::Active)
                    ->required()
                    ->disabled()
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Changed with the Deactivate / Reactivate actions and separations — access follows employment.'),
                TextInput::make('category')
                    ->maxLength(255),
                TextInput::make('level')
                    ->maxLength(255),
                FileUpload::make('photo_path')
                    ->label('Photo')
                    ->image()
                    ->avatar()
                    ->disk('public')
                    ->directory('employee-photos'),
            ]);
    }

    /**
     * Phase 8.4: current employees inside the editor's hierarchy, never someone in this employee's
     * own reporting line (HierarchyIntegrityService re-checks all of it on save).
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private static function assignableManagers(Builder $query, ?Employee $record): Builder
    {
        $actor = auth()->user();
        $visible = $actor instanceof User ? app(HierarchyService::class)->visibleEmployeeIdsFor($actor) : collect();

        return $query
            ->whereKeyNot($record?->id)
            ->where('employees.status', EmployeeStatus::Active->value)
            ->when($visible !== null, fn (Builder $scoped) => $scoped->whereIn('employees.id', $visible))
            ->when($record !== null, fn (Builder $scoped) => $scoped->whereNotIn('employees.id', app(HierarchyService::class)->descendantIdsOf($record->id)));
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(EmployeeStatus::cases())
            ->mapWithKeys(fn (EmployeeStatus $status) => [$status->value => $status->label()])
            ->all();
    }
}
