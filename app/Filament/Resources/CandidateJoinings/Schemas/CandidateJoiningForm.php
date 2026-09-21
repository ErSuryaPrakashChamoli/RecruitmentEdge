<?php

namespace App\Filament\Resources\CandidateJoinings\Schemas;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
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
                ApplicationPicker::make()
                    ->required(),
                Select::make('offer_id')
                    ->relationship('offer', 'offer_code')
                    ->searchable()
                    ->preload(),
                DatePicker::make('expected_doj')
                    ->required(),
                Select::make('documents_status')
                    ->label('Documents Status')
                    ->options(collect(DocumentStatus::cases())->mapWithKeys(fn (DocumentStatus $s) => [$s->value => $s->label()]))
                    ->default(DocumentStatus::Pending->value)
                    ->required(),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
