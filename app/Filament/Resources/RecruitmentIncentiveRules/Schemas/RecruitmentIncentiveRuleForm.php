<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Schemas;

use App\Enums\EmploymentType;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\TargetMetric;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentIncentiveRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Section::make('Trigger & Achievement')
                    ->columns(2)
                    ->schema([
                        Select::make('trigger_event')
                            ->options(collect(IncentiveTriggerEvent::cases())->mapWithKeys(fn (IncentiveTriggerEvent $e) => [$e->value => $e->label()]))
                            ->required()
                            ->helperText('Only "On Joining" rules are calculated automatically today; other triggers require the manual "Calculate Incentives" action.'),
                        Select::make('achievement_metric')
                            ->label('Achievement Metric (optional)')
                            ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()]))
                            ->helperText('Leave blank for a flat amount regardless of achievement — create a single slab covering 0 and up.'),
                        TextInput::make('retention_days')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Leave blank to skip a retention hold. If set, the calculation stays in "Calculated" until this many days after the trigger event.'),
                    ]),
                Section::make('Scope')
                    ->description('Leave every field blank to apply this rule to everyone.')
                    ->columns(3)
                    ->schema([
                        Select::make('employee_id')
                            ->label('Recruiter')
                            ->relationship('employee', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                        Select::make('department_id')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('designation_id')
                            ->relationship('designation', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('employment_type')
                            ->options(collect(EmploymentType::cases())->mapWithKeys(fn (EmploymentType $t) => [$t->value => $t->label()])),
                    ]),
                Section::make('Effective Period')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')
                            ->default(now())
                            ->required(),
                        DatePicker::make('effective_to'),
                    ]),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
