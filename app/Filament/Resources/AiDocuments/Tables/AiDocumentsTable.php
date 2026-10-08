<?php

namespace App\Filament\Resources\AiDocuments\Tables;

use App\Jobs\AI\IndexAiDocumentJob;
use App\Models\AiDocument;
use App\Models\AuditLog;
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
                IconColumn::make('privacy_declared_at')
                    ->label('No personal data declared')
                    ->boolean()
                    ->getStateUsing(fn (AiDocument $record): bool => $record->isPrivacyDeclared())
                    ->tooltip(fn (AiDocument $record): ?string => $record->isPrivacyDeclared() ? null : 'Not used by the AI until declared'),
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
                Action::make('declarePrivacy')
                    ->label('Declare no personal data')
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn (AiDocument $record): bool => ! $record->isPrivacyDeclared())
                    ->requiresConfirmation()
                    ->modalDescription('Documents uploaded before Phase 8.1 are not used by the AI until you confirm they contain no candidate or employee personal data (names, contact details, pay, IDs or private notes). The document is then re-indexed with contact details and IDs removed.')
                    ->action(function (AiDocument $record): void {
                        $record->forceFill(['privacy_declared_at' => now(), 'privacy_declared_by' => auth()->id()])->save();
                        AuditLog::record($record, 'ai_document_privacy_declared', ['privacy_declared_at' => null], ['declared_by' => auth()->id()]);
                        IndexAiDocumentJob::dispatch($record->id);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
