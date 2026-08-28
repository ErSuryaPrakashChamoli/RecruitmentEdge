<?php

namespace App\Filament\Resources\CandidateApplications\Tables;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRejectionReason;
use App\Services\StageTransitionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CandidateApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('application_code')
                    ->label('Application')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('candidate.full_name')
                    ->label('Candidate')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requisition.code')
                    ->label('Requisition')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->recruiter->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('current_stage')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStage $state) => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state) => $state->label())
                    ->color(fn (ApplicationStatus $state) => match ($state) {
                        ApplicationStatus::Active => 'success',
                        ApplicationStatus::Rejected, ApplicationStatus::Dropout => 'danger',
                        ApplicationStatus::OnHold => 'warning',
                    }),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (Priority $state) => $state->label()),
                TextColumn::make('next_followup_at')
                    ->label('Next Follow-up')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('current_stage')
                    ->options(collect(CandidateStage::cases())->mapWithKeys(fn (CandidateStage $s) => [$s->value => $s->label()])),
                SelectFilter::make('status')
                    ->options(collect(ApplicationStatus::cases())->mapWithKeys(fn (ApplicationStatus $s) => [$s->value => $s->label()])),
                SelectFilter::make('requisition')
                    ->relationship('requisition', 'code'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                self::advanceStageAction(),
                self::rejectAction(),
                self::dropoutAction(),
                self::reactivateAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    private static function advanceStageAction(): Action
    {
        return Action::make('advanceStage')
            ->label('Advance Stage')
            ->icon('heroicon-o-arrow-right-circle')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('transitionStage', $record))
            ->schema(fn (CandidateApplication $record) => [
                Select::make('stage')
                    ->label('New Stage')
                    ->options(collect(CandidateStage::cases())
                        ->filter(fn (CandidateStage $s) => $s->order() >= $record->current_stage->order())
                        ->mapWithKeys(fn (CandidateStage $s) => [$s->value => $s->label()])
                        ->all())
                    ->default($record->current_stage->value)
                    ->required(),
                Textarea::make('remarks'),
            ])
            ->action(function (CandidateApplication $record, array $data): void {
                app(StageTransitionService::class)->transitionTo(
                    $record,
                    CandidateStage::from($data['stage']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                );

                Notification::make()->title('Stage updated')->success()->send();
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('update', $record))
            ->schema([
                Select::make('rejection_reason_id')
                    ->label('Reason')
                    ->relationship('rejectionReason', 'name')
                    ->required()
                    ->searchable(),
                Textarea::make('remarks'),
            ])
            ->action(function (CandidateApplication $record, array $data): void {
                app(StageTransitionService::class)->reject(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['rejection_reason_id']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                );

                Notification::make()->title('Application rejected')->success()->send();
            });
    }

    private static function dropoutAction(): Action
    {
        return Action::make('dropout')
            ->label('Dropout')
            ->color('danger')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('update', $record))
            ->schema([
                Select::make('dropout_reason_id')
                    ->label('Reason')
                    ->relationship('dropoutReason', 'name')
                    ->required()
                    ->searchable(),
                Textarea::make('remarks'),
            ])
            ->action(function (CandidateApplication $record, array $data): void {
                app(StageTransitionService::class)->dropout(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['dropout_reason_id']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                );

                Notification::make()->title('Application marked as dropout')->success()->send();
            });
    }

    private static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->color('gray')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (CandidateApplication $record): bool => $record->status !== ApplicationStatus::Active
                && (bool) auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->action(function (CandidateApplication $record): void {
                app(StageTransitionService::class)->reactivate($record, auth()->user()?->employee);

                Notification::make()->title('Application reactivated')->success()->send();
            });
    }
}
