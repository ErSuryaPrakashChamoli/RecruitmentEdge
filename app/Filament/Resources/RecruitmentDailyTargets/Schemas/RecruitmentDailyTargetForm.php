<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Schemas;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->relationship('employee', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload()
                            ->requiredWithoutAll(['department_id', 'designation_id'])
                            ->prohibits(['department_id', 'designation_id'])
                            ->validationMessages(self::scopeValidationMessages()),
                        Select::make('department_id')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload()
                            ->requiredWithoutAll(['employee_id', 'designation_id'])
                            ->prohibits(['employee_id', 'designation_id'])
                            ->validationMessages(self::scopeValidationMessages()),
                        Select::make('designation_id')
                            ->relationship('designation', 'name')
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
