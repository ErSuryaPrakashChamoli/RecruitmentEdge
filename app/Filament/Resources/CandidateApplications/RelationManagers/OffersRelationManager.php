<?php

namespace App\Filament\Resources\CandidateApplications\RelationManagers;

use App\Enums\OfferStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only summary for the application's 360 view — full offer lifecycle actions stay on the
 * dedicated Offers resource (OffersTable), not duplicated here.
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Offers';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('offer_code')
            ->columns([
                TextColumn::make('offer_code'),
                TextColumn::make('offered_ctc')
                    ->money('INR'),
                TextColumn::make('offer_date')
                    ->date(),
                TextColumn::make('expected_joining_date')
                    ->date(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OfferStatus $state) => $state->label())
                    ->color(fn (OfferStatus $state) => $state->color()),
            ])
            ->defaultSort('offer_date', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
