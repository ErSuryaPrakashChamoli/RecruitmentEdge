<?php

namespace App\Filament\Resources\RecruitmentFollowups\Tables;

use App\Enums\FollowupStatus;
use App\Enums\FollowupType;
use App\Models\RecruitmentFollowup;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RecruitmentFollowupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('candidateApplication.application_code')
                    ->label('Application')
                    ->searchable(),
                TextColumn::make('candidateApplication.candidate.full_name')
                    ->label('Candidate')
                    ->searchable(),
                TextColumn::make('recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->recruiter->fullName()),
                TextColumn::make('followup_type')
                    ->badge()
                    ->formatStateUsing(fn (FollowupType $state) => $state->label()),
                TextColumn::make('followup_date')
                    ->label('Due')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (RecruitmentFollowup $record) => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (RecruitmentFollowup $record) => $record->isOverdue() ? 'bold' : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (FollowupStatus $state) => $state->label())
                    ->color(fn (FollowupStatus $state) => match ($state) {
                        FollowupStatus::Pending => 'warning',
                        FollowupStatus::Completed => 'success',
                        FollowupStatus::Missed, FollowupStatus::Cancelled => 'danger',
                    }),
            ])
            ->defaultSort('followup_date')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(FollowupStatus::cases())->mapWithKeys(fn (FollowupStatus $s) => [$s->value => $s->label()])),
                TernaryFilter::make('overdue')
                    ->label('Overdue')
                    ->queries(
                        true: fn ($query) => $query->where('status', FollowupStatus::Pending)->where('followup_date', '<', now()),
                        false: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                self::completeAction(),
                self::markMissedAction(),
                self::cancelAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Mark Completed')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (RecruitmentFollowup $record) => $record->status === FollowupStatus::Pending)
            ->schema([
                TextInput::make('outcome')->required(),
            ])
            ->action(function (RecruitmentFollowup $record, array $data): void {
                $record->update(['status' => FollowupStatus::Completed, 'outcome' => $data['outcome']]);
                Notification::make()->title('Follow-up marked completed')->success()->send();
            });
    }

    private static function markMissedAction(): Action
    {
        return Action::make('markMissed')
            ->label('Mark Missed')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (RecruitmentFollowup $record) => $record->status === FollowupStatus::Pending)
            ->requiresConfirmation()
            ->action(function (RecruitmentFollowup $record): void {
                $record->update(['status' => FollowupStatus::Missed]);
                Notification::make()->title('Follow-up marked missed')->success()->send();
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancelFollowup')
            ->label('Cancel')
            ->color('gray')
            ->icon('heroicon-o-no-symbol')
            ->visible(fn (RecruitmentFollowup $record) => $record->status === FollowupStatus::Pending)
            ->requiresConfirmation()
            ->action(function (RecruitmentFollowup $record): void {
                $record->update(['status' => FollowupStatus::Cancelled]);
                Notification::make()->title('Follow-up cancelled')->success()->send();
            });
    }
}
