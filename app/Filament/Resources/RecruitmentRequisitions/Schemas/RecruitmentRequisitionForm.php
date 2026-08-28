<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Schemas;

use App\Enums\EmploymentType;
use App\Enums\Priority;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentRequisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Position')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Requisition Code')
                            ->disabled()
                            ->dehydrated(false)
                            ->hidden(fn (string $operation): bool => $operation === 'create'),
                        Select::make('department_id')
                            ->relationship('department', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('designation_id')
                            ->relationship('designation', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('location_id')
                            ->relationship('location', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('openings')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Select::make('employment_type')
                            ->options(collect(EmploymentType::cases())->mapWithKeys(fn (EmploymentType $t) => [$t->value => $t->label()]))
                            ->required(),
                        Select::make('priority')
                            ->options(collect(Priority::cases())->mapWithKeys(fn (Priority $p) => [$p->value => $p->label()]))
                            ->default(Priority::Medium)
                            ->required(),
                    ]),
                Section::make('Requirements')
                    ->columns(2)
                    ->schema([
                        TextInput::make('qualification')
                            ->maxLength(255),
                        TextInput::make('shift')
                            ->maxLength(255),
                        TextInput::make('experience_min')
                            ->label('Min Experience (years)')
                            ->numeric(),
                        TextInput::make('experience_max')
                            ->label('Max Experience (years)')
                            ->numeric(),
                        TextInput::make('salary_min')
                            ->label('Min Salary')
                            ->numeric(),
                        TextInput::make('salary_max')
                            ->label('Max Salary')
                            ->numeric(),
                        TextInput::make('skills')
                            ->label('Skills (comma separated)')
                            ->dehydrateStateUsing(fn (?string $state): array => collect(explode(',', (string) $state))->map(trim(...))->filter()->all())
                            ->formatStateUsing(fn (?array $state): string => implode(', ', $state ?? []))
                            ->columnSpanFull(),
                    ]),
                Section::make('Ownership')
                    ->columns(2)
                    ->schema([
                        Select::make('reporting_manager_id')
                            ->label('Reporting Manager')
                            ->relationship('reportingManager', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                        Select::make('hiring_manager_id')
                            ->label('Hiring Manager')
                            ->relationship('hiringManager', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                        Select::make('assistant_manager_id')
                            ->label('Assistant Manager')
                            ->relationship('assistantManager', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                        Select::make('manager_id')
                            ->label('Manager')
                            ->relationship('manager', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                        Select::make('vp_hr_id')
                            ->label('VP HR')
                            ->relationship('vpHr', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                        Select::make('recruiters')
                            ->label('Assigned Recruiters')
                            ->relationship('recruiters', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ]),
                Section::make('Timeline')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('target_joining_date'),
                        DatePicker::make('opening_date')
                            ->default(now()),
                        DatePicker::make('closing_date'),
                    ]),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
