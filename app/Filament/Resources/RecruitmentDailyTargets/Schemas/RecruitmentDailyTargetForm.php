<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Schemas;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Filament\Support\ActiveMasterDataOptions;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RecruitmentDailyTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Scope')
                    ->description('Set exactly one: a recruiter-specific target overrides a designation target, which overrides a department target.')
                    ->columns(3)
                    ->schema([
                        Select::make('employee_id')
                            ->label('Recruiter')
                            ->relationship('employee', 'first_name', fn (Builder $query): Builder => self::visibleEmployees($query))
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload()
                            ->requiredWithoutAll(['department_id', 'designation_id'])
                            ->prohibits(['department_id', 'designation_id'])
                            ->validationMessages(self::scopeValidationMessages()),
                        Select::make('department_id')
                            ->relationship('department', 'name', ActiveMasterDataOptions::scope('department_id'))
                            ->visible(fn (): bool => self::seesWholeOrganisation())
                            ->searchable()
                            ->preload()
                            ->requiredWithoutAll(['employee_id', 'designation_id'])
                            ->prohibits(['employee_id', 'designation_id'])
                            ->validationMessages(self::scopeValidationMessages()),
                        Select::make('designation_id')
                            ->relationship('designation', 'name', ActiveMasterDataOptions::scope('designation_id'))
                            ->visible(fn (): bool => self::seesWholeOrganisation())
                            ->searchable()
                            ->preload()
                            ->requiredWithoutAll(['employee_id', 'department_id'])
                            ->prohibits(['employee_id', 'department_id'])
                            ->validationMessages(self::scopeValidationMessages()),
                    ]),
                Section::make('Target')
                    ->columns(2)
                    ->schema([
                        Select::make('metric')
                            ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()]))
                            ->required(),
                        Select::make('period_type')
                            ->options(collect(TargetPeriodType::cases())->mapWithKeys(fn (TargetPeriodType $p) => [$p->value => $p->label()]))
                            ->default(TargetPeriodType::Daily)
                            ->helperText('Weeks run Monday–Sunday. A report range that isn\'t exactly one day, week, or month prorates the most specific target configured.')
                            ->required(),
                        TextInput::make('target_value')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        DatePicker::make('effective_from')
                            ->default(now())
                            ->required(),
                        DatePicker::make('effective_to'),
                    ]),
            ]);
    }

    /**
     * Phase 8.9 (P89-SEC-001): only recruiters in the user's own hierarchy can be picked —
     * RecruitmentTargetService refuses anyone else.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private static function visibleEmployees(Builder $query): Builder
    {
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor(self::user());

        return $visibleIds === null ? $query : $query->whereIn($query->qualifyColumn('id'), $visibleIds);
    }

    /**
     * Department and designation targets reach people outside any one team: hierarchy.view-all only.
     */
    private static function seesWholeOrganisation(): bool
    {
        return self::user()->can('hierarchy.view-all');
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private static function scopeValidationMessages(): array
    {
        $message = 'Choose exactly one of Recruiter, Department, or Designation for this target.';

        return [
            'required_without_all' => $message,
            'prohibits' => $message,
        ];
    }
}
