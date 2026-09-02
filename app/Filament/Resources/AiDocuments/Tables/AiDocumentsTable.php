<?php

namespace App\Filament\Resources\AiDocuments\Tables;

use App\Jobs\AI\IndexAiDocumentJob;
use App\Models\AiDocument;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AiDocument $record) => match ($record->status->value) {
                        'indexed' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (AiDocument $record) => $record->status->label()),
                IconColumn::make('is_published')
                    ->boolean(),
                TextColumn::make('uploader.first_name')
                    ->label('Uploaded by')
                    ->formatStateUsing(fn (AiDocument $record) => $record->uploader?->fullName() ?? '—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'indexed' => 'Indexed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->recordActions([
                Action::make('reindex')
                    ->label('Re-index')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (AiDocument $record) => IndexAiDocumentJob::dispatch($record->id)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
