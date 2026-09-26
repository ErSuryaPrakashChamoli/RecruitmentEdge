<?php

namespace App\Filament\Resources\EmployeeSeparations\Schemas;

use App\Enums\EmployeeStatus;
use App\Enums\SeparationReason;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
                    ->helperText('Only current employees in your hierarchy without an open separation are listed.'),
                DatePicker::make('separation_date')
                    ->label('Last working day')
                    ->required()
                    ->live()
                    ->disabled(fn (?EmployeeSeparation $record): bool => $record?->effective_applied_at !== null)
                    ->helperText('Access continues until the end of this day, then the separation takes effect. A future date is fine.'),
                Toggle::make('revoke_access_now')
                    ->label('End system access now')
                    ->helperText('For someone leaving before their last working day: their login is revoked immediately; employment still ends on the date.')
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && filled($get('separation_date')) && Carbon::parse($get('separation_date'))->gte(today()))
                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
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
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $current) => $current
                    ->where('status', '!=', EmployeeStatus::Separated->value)
                    ->whereDoesntHave('separations', fn (Builder $open) => $open->whereNull('cancelled_at')->whereNull('effective_applied_at'))
                    ->when($user instanceof User && $user->employee_id !== null, fn (Builder $notSelf) => $notSelf->whereKeyNot($user->employee_id)))
                ->when($record !== null, fn (Builder $q) => $q->orWhere('id', $record->employee_id)))
            ->orderBy('first_name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName().' ('.$employee->employee_code.')'])
            ->all();
    }
}
