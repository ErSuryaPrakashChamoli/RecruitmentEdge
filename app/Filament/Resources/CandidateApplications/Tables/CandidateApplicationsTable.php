<?php

namespace App\Filament\Resources\CandidateApplications\Tables;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Filament\Exports\CandidateApplicationExporter;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\RequisitionPipelineStage;
use App\Services\ApplicationAssignmentService;
use App\Services\OfferService;
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
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
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
                self::moveToRequisitionAction(),
                self::reassignRecruiterAction(),
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
     * candidate being looked at rather than by picking an application code from memory. Offered only
     * where OfferService allows an offer (Selected, active, no other open offer — Phase 8.3).
     */
    public static function raiseOfferAction(): Action
    {
        return Action::make('raiseOffer')
            ->label('Raise Offer')
            ->color('success')
            ->icon('heroicon-o-document-plus')
            ->url(fn (CandidateApplication $record): string => OfferResource::getUrl('create', ['application' => $record->getKey()]))
            ->visible(fn (CandidateApplication $record): bool => app(OfferService::class)->canRaiseOffer($record)
                && (bool) auth()->user()?->can('create', Offer::class));
    }

    /**
     * Applications on a configured pipeline pick from that pipeline's allowed next stages
     * (StageTransitionService::moveToStage() enforces the rules; users with pipeline.override may
     * override them with a reason). Legacy applications keep the canonical stage list.
     */
    public static function advanceStageAction(): Action
    {
        return Action::make('advanceStage')
            ->label('Advance Stage')
            ->icon('heroicon-o-arrow-right-circle')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('transitionStage', $record))
            ->schema(fn (CandidateApplication $record): array => $record->pipeline_stage_id !== null
                ? self::pipelineStageFields($record)
                : [
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
            ->action(fn (CandidateApplication $record, array $data) => self::performStageMove($record, $data));
    }

    /**
     * @return array<int, Component>
     */
    public static function pipelineStageFields(CandidateApplication $record): array
    {
        $transitions = app(StageTransitionService::class);
        $canOverride = (bool) auth()->user()?->can('pipeline.override');

        return [
            Toggle::make('override')
                ->label('Override pipeline rules')
                ->helperText('Move to any later stage, bypassing transition rules. A reason is required and the override is audited.')
                ->visible($canOverride)
                ->live(),
            Select::make('pipeline_stage_id')
                ->label('New Stage')
                ->options(fn (Get $get): array => ($get('override') ? $transitions->overridableStages($record) : $transitions->allowedNextStages($record))
                    ->mapWithKeys(fn (RequisitionPipelineStage $stage) => [$stage->id => $stage->name])
                    ->all())
                ->helperText(fn (): string => 'Currently: '.($record->pipelineStage?->name ?? $record->current_stage->label()))
                ->required(),
            Textarea::make('remarks')
                ->required(fn (Get $get): bool => (bool) $get('override')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function performStageMove(CandidateApplication $record, array $data): void
    {
        $actor = auth()->user()?->employee;
        $remarks = $data['remarks'] ?? null;

        if (filled($data['pipeline_stage_id'] ?? null)) {
            $target = RequisitionPipelineStage::query()->findOrFail($data['pipeline_stage_id']);

            self::performTransition(
                fn (StageTransitionService $service) => $service->moveToStage($record, $target, $actor, $remarks, (bool) ($data['override'] ?? false)),
                'Stage updated',
            );

            return;
        }

        self::performTransition(
            fn (StageTransitionService $service) => $service->advance($record, CandidateStage::from($data['stage']), $actor, $remarks),
            'Stage updated',
        );
    }

    /**
     * Phase 8.3: moving an application to another requisition is explicit and reasoned — the edit
     * form no longer changes the requisition (ApplicationAssignmentService enforces the rules).
     */
    public static function moveToRequisitionAction(): Action
    {
        return Action::make('moveToRequisition')
            ->label('Move to requisition')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (CandidateApplication $record): bool => $record->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('move', $record))
            ->schema(fn (CandidateApplication $record): array => [
                Select::make('requisition_id')
                    ->label('Destination requisition')
                    ->options(fn (): array => RecruitmentRequisitionResource::applicationTargetQuery()
                        ->whereKeyNot($record->requisition_id)
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')->required()->rows(2)->maxLength(255),
            ])
            ->modalDescription('The application keeps its stage and history; open interviews, offers and a joining must be resolved first. The move is audited.')
            ->action(fn (CandidateApplication $record, array $data) => self::performAssignment(
                fn (ApplicationAssignmentService $service) => $service->moveToRequisition($record, RecruitmentRequisition::query()->findOrFail($data['requisition_id']), auth()->user(), $data['reason']),
                'Application moved',
            ));
    }

    public static function reassignRecruiterAction(): Action
    {
        return Action::make('reassignRecruiter')
            ->label('Reassign recruiter')
            ->icon('heroicon-o-user-group')
            ->color('gray')
            ->visible(fn (CandidateApplication $record): bool => (bool) auth()->user()?->can('reassign', $record))
            ->schema([
                Select::make('recruiter_id')
                    ->label('New recruiter')
                    ->options(fn (): array => EmployeeResource::getEloquentQuery()->orderBy('first_name')->get()->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()])->all())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')->rows(2)->maxLength(255),
            ])
            ->action(fn (CandidateApplication $record, array $data) => self::performAssignment(
                fn (ApplicationAssignmentService $service) => $service->reassignRecruiter($record, Employee::query()->findOrFail($data['recruiter_id']), auth()->user(), $data['reason'] ?? null),
                'Recruiter reassigned',
            ));
    }

    public static function performAssignment(callable $change, string $successTitle): void
    {
        try {
            $change(app(ApplicationAssignmentService::class));
        } catch (DomainException $e) {
            Notification::make()->title('Application could not be updated')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($successTitle)->success()->send();
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
