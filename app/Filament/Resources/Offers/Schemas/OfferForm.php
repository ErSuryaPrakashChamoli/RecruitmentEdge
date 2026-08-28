<?php

namespace App\Filament\Resources\Offers\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OfferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('offer_code')
                    ->disabled()
                    ->dehydrated(false)
                    ->hidden(fn (string $operation): bool => $operation === 'create'),
                Select::make('candidate_application_id')
                    ->label('Application')
                    ->relationship('candidateApplication', 'application_code')
                    ->required()
                    ->searchable()
                    ->preload(),
                Section::make('Compensation')
                    ->columns(2)
                    ->schema([
                        Select::make('designation_id')
                            ->relationship('designation', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('location_id')
                            ->relationship('location', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('offered_ctc')
                            ->numeric(),
                        TextInput::make('fixed_salary')
                            ->numeric(),
                        TextInput::make('variable_salary')
                            ->numeric(),
                        TextInput::make('joining_bonus')
                            ->numeric(),
                    ]),
                Section::make('Timeline')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('offer_date')
                            ->default(now())
                            ->required(),
                        DatePicker::make('offer_expiry'),
                        DatePicker::make('expected_joining_date'),
                    ]),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
