<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlabsRelationManager extends RelationManager
{
    protected static string $relationship = 'slabs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('achievement_min')
                    ->label('Achievement % from')
                    ->numeric()
                    ->required(),
                TextInput::make('achievement_max')
                    ->label('Achievement % to')
                    ->numeric()
                    ->helperText('Leave blank for no upper bound.'),
                TextInput::make('amount')
                    ->numeric()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->defaultSort('achievement_min')
            ->columns([
                TextColumn::make('achievement_min')
                    ->label('From %'),
                TextColumn::make('achievement_max')
                    ->label('To %')
                    ->placeholder('No limit'),
                TextColumn::make('amount')
                    ->money('INR'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
