<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\Tables;

use App\Enums\InterviewMode;
use App\Enums\InterviewSlotStatus;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\InterviewAvailabilitySlot;
use App\Services\InterviewSchedulingService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InterviewAvailabilitySlotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['interviewer', 'requisition']))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('When')
                    ->formatStateUsing(fn (InterviewAvailabilitySlot $record) => $record->displayWindow())
                    ->sortable(),
                TextColumn::make('interviewer.first_name')->label('Interviewer')->formatStateUsing(fn (InterviewAvailabilitySlot $record) => $record->interviewer?->fullName()),
                TextColumn::make('requisition.code')->label('Position')->placeholder('Any'),
                TextColumn::make('mode')->formatStateUsing(fn (InterviewMode $state) => $state->label())->toggleable(),
                TextColumn::make('booked_count')->label('Booked')->formatStateUsing(fn (InterviewAvailabilitySlot $record) => "{$record->booked_count} / {$record->capacity}"),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InterviewSlotStatus $state) => $state->label())
                    ->color(fn (InterviewSlotStatus $state) => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(InterviewSlotStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                TernaryFilter::make('upcoming')
                    ->label('Upcoming only')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->where('starts_at', '>=', now()),
                        false: fn (Builder $q) => $q->where('starts_at', '<', now()),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                self::cancelSlotAction(),
            ])
            ->emptyStateHeading('No interview slots')
            ->emptyStateDescription('Publish interviewer availability so candidates can pick their own interview time.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function cancelSlotAction(): Action
    {
        return Action::make('cancelSlot')
            ->label('Withdraw')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (InterviewAvailabilitySlot $record): bool => in_array($record->status, [InterviewSlotStatus::Available, InterviewSlotStatus::Held, InterviewSlotStatus::Booked], true)
                && (bool) auth()->user()?->can('update', $record))
            ->schema([Textarea::make('reason')->required()->maxLength(1000)])
            ->action(function (InterviewAvailabilitySlot $record, array $data): void {
                InterviewsTable::guarded('Slot could not be withdrawn', fn () => app(InterviewSchedulingService::class)->cancelSlot($record, $data['reason'], auth()->user()?->employee));

                Notification::make()->title('Slot withdrawn')->success()->send();
            });
    }
}
