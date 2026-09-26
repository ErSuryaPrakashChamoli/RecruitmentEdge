<?php

namespace App\Filament\Resources\RecruitmentStages\Tables;

use App\Enums\CandidateStage;
use App\Enums\StageType;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\RecruitmentStage;
use App\Services\StageConfigurationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RecruitmentStagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('allowedNextStages'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order', fn (): bool => (bool) auth()->user()?->can('pipeline.configure'))
            // Filament persists the new order with one mass UPDATE (no model events); the service
            // writes the same positions first, through the model, so each move is audited.
            ->beforeReordering(fn (array $order) => app(StageConfigurationService::class)->reorder($order))
            ->columns([
                TextColumn::make('name')
                    ->badge()
                    ->color(fn (RecruitmentStage $record): string => $record->color)
                    ->icon(fn (RecruitmentStage $record): string => $record->resolvedIcon())
                    ->description(fn (RecruitmentStage $record): ?string => $record->is_system ? 'System stage' : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('stage_type')
                    ->label('Type')
                    ->formatStateUsing(fn (StageType $state): string => $state->label()),
                TextColumn::make('milestone')
                    ->label('Counts as')
                    ->formatStateUsing(fn (CandidateStage $state): string => $state->label())
                    ->toggleable(),
                TextColumn::make('sla_hours')
                    ->label('SLA')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state} h" : '—')
                    ->placeholder('—'),
                TextColumn::make('allowed_next_stages_count')
                    ->label('Transitions')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Default' : "{$state} explicit"),
                IconColumn::make('is_skippable')->label('Skippable')->boolean()->toggleable(),
                IconColumn::make('is_terminal')->label('Terminal')->boolean()->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('stage_type')->options(StageType::options()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                self::toggleActiveAction(),
            ])
            ->emptyStateHeading('No hiring stages yet')
            ->emptyStateDescription('Run the reference data seeder to create the standard stages, or add your own.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    public static function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (RecruitmentStage $record): string => $record->is_active ? 'Deactivate' : 'Activate')
            ->icon(fn (RecruitmentStage $record): string => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->color(fn (RecruitmentStage $record): string => $record->is_active ? 'warning' : 'success')
            ->requiresConfirmation()
            ->modalDescription('Inactive stages cannot be added to templates. Pipelines already applied to requisitions are unaffected.')
            ->visible(fn (RecruitmentStage $record): bool => (bool) auth()->user()?->can('update', $record))
            ->action(function (RecruitmentStage $record): void {
                InterviewsTable::guarded('Stage could not be updated', fn () => app(StageConfigurationService::class)->setActive($record, ! $record->is_active));

                Notification::make()->title($record->is_active ? 'Stage activated' : 'Stage deactivated')->success()->send();
            });
    }
}
