<?php

namespace App\Filament\Resources\JobPostings\RelationManagers;

use App\Enums\DistributionStatus;
use App\Services\Distribution\JobBoardRegistry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DistributionsRelationManager extends RelationManager
{
    protected static string $relationship = 'distributions';

    protected static ?string $title = 'Distribution';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel')->formatStateUsing(fn (string $state) => app(JobBoardRegistry::class)->find($state)?->label() ?? $state),
                TextColumn::make('status')->badge()->formatStateUsing(fn (DistributionStatus $state) => $state->label())->color(fn (DistributionStatus $state) => $state->color()),
                TextColumn::make('external_url')->label('Link')->url(fn ($state) => $state, shouldOpenInNewTab: true)->limit(40)->placeholder('—'),
                TextColumn::make('last_error')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('last_synced_at')->since()->placeholder('—'),
            ])
            ->emptyStateHeading('Not published anywhere yet');
    }
}
