<?php

namespace App\Filament\Resources\AutomationRules\RelationManagers;

use App\Models\AutomationRuleVersion;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Immutable configuration history of a rule — what each version did and who saved it.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy:id,name'))
            ->columns([
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('change_summary')->label('Change'),
                TextColumn::make('actions')->label('Actions')->state(fn (AutomationRuleVersion $record) => collect($record->snapshot['actions'] ?? [])->pluck('type')->implode(', ')),
                TextColumn::make('createdBy.name')->label('Saved by')->placeholder('System'),
                TextColumn::make('created_at')->label('Saved')->dateTime(),
            ]);
    }
}
