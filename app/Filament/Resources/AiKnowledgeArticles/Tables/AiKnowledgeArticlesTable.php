<?php

namespace App\Filament\Resources\AiKnowledgeArticles\Tables;

use App\Jobs\AI\ReindexKnowledgeArticleJob;
use App\Models\AiKnowledgeArticle;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiKnowledgeArticlesTable
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
                IconColumn::make('is_published')
                    ->boolean(),
                TextColumn::make('createdBy.first_name')
                    ->label('Author')
                    ->formatStateUsing(fn ($record) => $record->createdBy?->fullName() ?? '—'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options([
                        'policy' => 'Policy',
                        'process' => 'Process',
                        'faq' => 'FAQ',
                        'general' => 'General',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('reindex')
                    ->label('Re-index')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (AiKnowledgeArticle $record): bool => $record->is_published && (bool) auth()->user()?->can('ai.manage'))
                    ->action(function (AiKnowledgeArticle $record): void {
                        ReindexKnowledgeArticleJob::dispatch($record->id);

                        Notification::make()
                            ->title('Re-indexing queued')
                            ->body("\"{$record->title}\" will be re-embedded for AI search shortly.")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
