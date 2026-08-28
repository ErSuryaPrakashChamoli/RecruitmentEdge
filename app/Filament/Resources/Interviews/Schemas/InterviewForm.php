<?php

namespace App\Filament\Resources\Interviews\Schemas;

use App\Enums\InterviewMode;
use App\Models\Employee;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InterviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('candidate_application_id')
                    ->label('Application')
                    ->relationship('candidateApplication', 'application_code')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('round_number')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                TextInput::make('round_name')
                    ->maxLength(255),
                Select::make('interviewer_id')
                    ->relationship('interviewer', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                    ->required()
                    ->searchable()
                    ->preload(),
                DateTimePicker::make('scheduled_at')
                    ->required(),
                Select::make('mode')
                    ->options(collect(InterviewMode::cases())->mapWithKeys(fn (InterviewMode $m) => [$m->value => $m->label()]))
                    ->required(),
                TextInput::make('location')
                    ->label('Location / Meeting Link')
                    ->maxLength(255),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
