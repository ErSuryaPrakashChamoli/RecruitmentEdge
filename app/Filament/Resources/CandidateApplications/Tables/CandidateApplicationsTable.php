<?php

namespace App\Filament\Resources\CandidateApplications\Tables;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Filament\Exports\CandidateApplicationExporter;
use App\Filament\Resources\Offers\OfferResource;
use App\Models\CandidateApplication;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Services\StageTransitionService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
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
                    ->html()
                    ->formatStateUsing(fn ($record) => view('filament.tables.columns.person-name', [
                        'name' => $record->candidate->full_name,
                        'subtitle' => $record->candidate->mobile,
                    ]))
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
                    ->formatStateUsing(fn (CandidateStage $state) => $state->label())
                    ->color(fn (CandidateStage $state) => $state->color()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state) => $state->label())
                    ->color(fn (ApplicationStatus $state) => $state->color()),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (Priority $state) => $state->label())
                    ->color(fn (Priority $state) => $state->color()),
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
                SelectFilter::make('recruiter')
                    ->relationship('recruiter', 'first_name')
                    ->searchable(),
                TrashedFilter::make(),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(CandidateApplicationExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export')),
            ])
            ->recordActions([
                ViewAction::make(),
                self::raiseOfferAction()
                    ->button()
                    ->size('sm'),
                self::advanceStageAction(),
                self::rejectAction(),
                self::dropoutAction(),
                self::holdAction(),
                self::reactivateAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No applications found')
            ->emptyStateDescription('Try changing your filters, or add a new candidate to a requisition to create one.')
            ->emptyStateIcon('heroicon-o-queue-list');
    }

    /**
     * Opens the offer form with this application already selected, so an offer is raised from the
     * candidate being looked at rather than by picking an application code from memory. Offered on
     * any active application until an offer has been accepted.
     */
    public static function raiseOfferAction(): Action
    {
        return Action::make('raiseOffer')
            ->label('Raise Offer')
            ->color('success')
            ->icon('heroicon-o-document-plus')
            ->url(fn (CandidateApplication $record): string => OfferResource::getUrl('create', ['application' => $record->getKey()]))
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && $record->current_stage->order() < CandidateStage::OfferAccepted->order()
                && (bool) auth()->user()?->can('create', Offer::class));
    }

    public static function advanceStageAction(): Action
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
            ->action(fn (CandidateApplication $record, array $data) => self::performTransition(
                fn (StageTransitionService $service) => $service->transitionTo(
                    $record,
                    CandidateStage::from($data['stage']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                ),
                'Stage updated',
            ));
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('update', $record))
            ->schema([
                self::reasonSelect('rejection_reason_id'),
                Textarea::make('remarks'),
            ])
            ->action(fn (CandidateApplication $record, array $data) => self::performTransition(
                fn (StageTransitionService $service) => $service->reject(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['rejection_reason_id']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                ),
                'Application rejected',
            ));
    }

    public static function dropoutAction(): Action
    {
        return Action::make('dropout')
            ->label('Dropout')
            ->color('danger')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('update', $record))
            ->schema([
                self::reasonSelect('dropout_reason_id'),
                Textarea::make('remarks'),
            ])
            ->action(fn (CandidateApplication $record, array $data) => self::performTransition(
                fn (StageTransitionService $service) => $service->dropout(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['dropout_reason_id']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                ),
                'Application marked as dropout',
            ));
    }

    public static function holdAction(): Action
    {
        return Action::make('hold')
            ->label('Put On Hold')
            ->color('warning')
            ->icon('heroicon-o-pause-circle')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('update', $record))
            ->schema([
                Textarea::make('remarks')
                    ->required(),
            ])
            ->action(fn (CandidateApplication $record, array $data) => self::performTransition(
                fn (StageTransitionService $service) => $service->hold(
                    $record,
                    auth()->user()?->employee,
                    $data['remarks'],
                ),
                'Application put on hold',
            ));
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->color('gray')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (CandidateApplication $record): bool => $record->status !== ApplicationStatus::Active
                && (bool) auth()->user()?->can('update', $record))
            ->modalSubmitActionLabel('Reactivate')
            ->schema([
                Textarea::make('remarks'),
            ])
            ->action(fn (CandidateApplication $record, array $data) => self::performTransition(
                fn (StageTransitionService $service) => $service->reactivate(
                    $record,
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                ),
                'Application reactivated',
            ));
    }

    /**
     * Rejection/dropout reason picker: only active reasons, grouped by RejectionCategory.
     * StageTransitionService re-checks that the chosen reason is still active.
     */
    public static function reasonSelect(string $name): Select
    {
        return Select::make($name)
            ->label('Reason')
            ->options(fn (): array => RecruitmentRejectionReason::groupedActiveOptions())
            ->required()
            ->searchable();
    }

    /**
     * StageTransitionService enforces the state machine for every write path, so its guards
     * arrive here as DomainException. Surface them as a notification and halt the action — an
     * uncaught one renders a 500 error page over the panel.
     *
     * @param  callable(StageTransitionService): mixed  $transition
     */
    public static function performTransition(callable $transition, string $successTitle): void
    {
        try {
            $transition(app(StageTransitionService::class));
        } catch (DomainException $e) {
            Notification::make()
                ->title('Application could not be updated')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
