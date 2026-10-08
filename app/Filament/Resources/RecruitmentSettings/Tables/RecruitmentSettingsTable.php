<?php

namespace App\Filament\Resources\RecruitmentSettings\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruitmentSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->limit(50),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('group')
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('group'),
            ])
            // Phase 8.6 (D8.6-013): read-only; change settings on the Configure page.
            ->recordActions([]);
    }
}
