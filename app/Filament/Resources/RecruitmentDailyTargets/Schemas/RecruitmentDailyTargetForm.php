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
                            ->preload(),
                        Select::make('department_id')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('designation_id')
                            ->relationship('designation', 'name')
                            ->searchable()
                            ->preload(),
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
}
