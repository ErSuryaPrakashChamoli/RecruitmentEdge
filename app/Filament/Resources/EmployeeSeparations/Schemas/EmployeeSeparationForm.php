<?php

namespace App\Filament\Resources\EmployeeSeparations\Schemas;

use App\Enums\SeparationReason;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EmployeeSeparationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->options(fn (?EmployeeSeparation $record): array => self::employeeOptions($record))
                    ->searchable()
                    ->required()
                    ->disabledOn('edit')
                    ->helperText('Only employees in your hierarchy without a separation record are listed.'),
                DatePicker::make('separation_date')
                    ->label('Last working day')
                    ->required()
                    ->maxDate(now()),
                Select::make('separation_reason')
                    ->label('Reason')
                    ->options(SeparationReason::options())
                    ->required(),
                Textarea::make('notes')
                    ->rows(3)
                    ->maxLength(1000)
                    ->helperText('Optional. Shown only here — never included in outcome analytics, AI or the audit log.'),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function employeeOptions(?EmployeeSeparation $record): array
    {
        $user = auth()->user();
        $visibleIds = $user instanceof User ? app(HierarchyService::class)->visibleEmployeeIdsFor($user) : collect();

        return Employee::query()
            ->when($visibleIds !== null, fn (Builder $query) => $query->whereIn('id', $visibleIds))
            ->where(fn (Builder $query) => $query->whereDoesntHave('separation')->when($record !== null, fn (Builder $q) => $q->orWhere('id', $record->employee_id)))
            ->orderBy('first_name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName().' ('.$employee->employee_code.')'])
            ->all();
    }
}
