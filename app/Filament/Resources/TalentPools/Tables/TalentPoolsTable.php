<?php

namespace App\Filament\Resources\TalentPools\Tables;

use App\Enums\TalentPoolStatus;
use App\Enums\TalentPoolVisibility;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\TalentPool;
use App\Services\TalentPoolService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TalentPoolsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('owner')->withCount('activeMemberships'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TalentPool $record): ?string => $record->description),
                TextColumn::make('active_memberships_count')
                    ->label('Candidates')
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (TalentPoolVisibility $state) => $state->label()),
                TextColumn::make('owner.first_name')
                    ->label('Owner')
                    ->formatStateUsing(fn (TalentPool $record) => $record->owner?->fullName()),
                TextColumn::make('tags')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TalentPoolStatus $state) => $state->label())
                    ->color(fn (TalentPoolStatus $state) => $state->color()),
                TextColumn::make('updated_at')->label('Last activity')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TalentPoolStatus::cases())->mapWithKeys(fn (TalentPoolStatus $s) => [$s->value => $s->label()]))
                    ->default(TalentPoolStatus::Active->value),
                SelectFilter::make('visibility')->options(TalentPoolVisibility::options()),
            ])
            ->recordActions([
                EditAction::make(),
                self::archiveAction(),
            ])
            ->emptyStateHeading('No talent pools yet')
            ->emptyStateDescription('Create a pool to keep promising candidates — silver medalists, future leaders, skill groups — ready for the next opening.')
            ->emptyStateIcon('heroicon-o-rectangle-group');
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(fn (TalentPool $record): string => $record->isActive() ? 'Archive' : 'Restore')
            ->icon(fn (TalentPool $record): string => $record->isActive() ? 'heroicon-o-archive-box' : 'heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (TalentPool $record): string => $record->isActive()
                ? 'Archived pools keep their members but no new candidates can be added.'
                : 'The pool becomes active again.')
            ->visible(fn (TalentPool $record): bool => (bool) auth()->user()?->can('archive', $record))
            ->action(function (TalentPool $record): void {
                $service = app(TalentPoolService::class);

                InterviewsTable::guarded('Talent pool could not be updated', fn () => $record->isActive()
                    ? $service->archive($record, auth()->user()?->employee)
                    : $service->restore($record));

                Notification::make()->title($record->isActive() ? 'Talent pool restored' : 'Talent pool archived')->success()->send();
            });
    }
}
