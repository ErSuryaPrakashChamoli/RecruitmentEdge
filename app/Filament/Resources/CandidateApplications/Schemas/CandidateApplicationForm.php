<?php

namespace App\Filament\Resources\CandidateApplications\Schemas;

use App\Enums\Priority;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CandidateApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('application_code')
                            ->disabled()
                            ->dehydrated(false)
                            ->hidden(fn (string $operation): bool => $operation === 'create'),
                        DatePicker::make('application_date')
                            ->default(now())
                            ->required(),
                        Select::make('candidate_id')
                            ->relationship('candidate', 'full_name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('requisition_id')
                            ->relationship('requisition', 'code')
                            ->required()
                            ->searchable()
                            ->preload(),
                    ]),
                Section::make('Assignment & Priority')
                    ->columns(2)
                    ->schema([
                        Select::make('recruiter_id')
                            ->label('Recruiter')
                            ->relationship('recruiter', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('priority')
                            ->options(collect(Priority::cases())->mapWithKeys(fn (Priority $p) => [$p->value => $p->label()]))
                            ->default(Priority::Medium)
                            ->required(),
                    ]),
                Section::make('Follow-up')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('next_followup_at'),
                        Textarea::make('remarks')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
