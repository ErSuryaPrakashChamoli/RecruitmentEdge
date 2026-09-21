<?php

namespace App\Filament\Resources\OfferLetterTemplates\Tables;

use App\Enums\OfferLetterTemplateFormat;
use App\Filament\Resources\OfferLetterTemplates\Actions\OfferLetterTemplateActions;
use App\Models\OfferLetterTemplate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OfferLetterTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (OfferLetterTemplate $record): ?string => $record->is_system ? 'Standard template — cannot be deleted' : null),
                TextColumn::make('format')
                    ->badge()
                    ->color(fn (OfferLetterTemplateFormat $state): string => $state === OfferLetterTemplateFormat::Word ? 'info' : 'gray')
                    ->formatStateUsing(fn (OfferLetterTemplateFormat $state): string => $state === OfferLetterTemplateFormat::Word ? 'Word file' : 'Rich text'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('offers_count')
                    ->label('Offers using it')
                    ->counts('offers'),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                OfferLetterTemplateActions::downloadWordFile(),
                OfferLetterTemplateActions::uploadWordFile(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (OfferLetterTemplate $record): bool => ! $record->is_system)
            ->emptyStateHeading('No offer letter templates yet')
            ->emptyStateDescription('Offers use the built-in letter until a template exists.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
