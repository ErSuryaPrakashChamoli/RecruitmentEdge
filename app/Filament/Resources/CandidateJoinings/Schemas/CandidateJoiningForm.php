<?php

namespace App\Filament\Resources\CandidateJoinings\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class CandidateJoiningForm
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
                Select::make('offer_id')
                    ->relationship('offer', 'offer_code')
                    ->searchable()
                    ->preload(),
                DatePicker::make('expected_doj')
                    ->required(),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
