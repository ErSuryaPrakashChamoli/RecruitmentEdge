<?php

namespace App\Filament\Resources\CandidateApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\FollowupType;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateApplications\Tables\CandidateApplicationsTable;
use App\Filament\Resources\Interviews\Schemas\InterviewForm;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\RecruitmentFollowup;
use App\Models\RequisitionPipelineStage;
use App\Services\CandidateTimelineService;
use App\Services\Intelligence\EvidenceLookup;
use App\Services\Intelligence\TalentSignalService;
use App\Services\InterviewSchedulingService;
use App\Services\InterviewService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Candidate 360 command center (Stage 4, Module 1). A custom $view wraps a new header/journey
 * /timeline above Filament's own {{ $this->content }} — which keeps every existing tab (Overview
 * infolist, Stage History/Interviews/Offers/Activities relation managers) rendering exactly as it
 * already does. Quick stage actions reuse the exact same Action builders as the index table
 * (CandidateApplicationsTable::advanceStageAction() etc.) rather than a second implementation, so
 * there is only ever one place that calls StageTransitionService for these transitions.
 */
class ViewCandidateApplication extends ViewRecord
{
    protected static string $resource = CandidateApplicationResource::class;

    protected string $view = 'filament.resources.candidate-applications.view';

    protected function getHeaderActions(): array
    {
        return [
            CandidateApplicationsTable::advanceStageAction(),
            $this->inviteToSelfScheduleAction(),
            $this->selectCandidateAction(),
            CandidateApplicationsTable::raiseOfferAction(),
            CandidateApplicationsTable::rejectAction(),
            CandidateApplicationsTable::dropoutAction(),
            CandidateApplicationsTable::holdAction(),
            CandidateApplicationsTable::reactivateAction(),
            $this->talentSignalAction(),
            EditAction::make(),
            // Phase 8.3: moving an application and reassigning its recruiter are explicit operations,
            // grouped so the header keeps its width.
            ActionGroup::make([
                CandidateApplicationsTable::moveToRequisitionAction(),
                CandidateApplicationsTable::reassignRecruiterAction(),
            ])->label('Reassign')->icon('heroicon-o-arrows-right-left')->button()->color('gray'),
        ];
    }

    /**
     * EDGE Intelligence (Phase 7): this candidate's Talent Signal against the requisition's current
     * Role DNA — components and evidence. Recomputed on open only if stale (deterministic, no AI).
     */
    protected function talentSignalAction(): Action
    {
        return Action::make('talentSignal')
            ->label('Talent Signal')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('intelligence.view') ?? false)
            ->slideOver()
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalHeading('Talent Signal')
            ->modalDescription('How this candidate aligns with the role as defined in its Role DNA — components and the facts behind them, not a single score. Advisory only.')
            ->modalContent(function () {
                /** @var CandidateApplication $record */
                $record = $this->getRecord();
                $snapshot = app(TalentSignalService::class)->refresh($record);

                return view('filament.intelligence.talent-signal', [
                    'snapshot' => $snapshot,
                    'evidence' => app(EvidenceLookup::class)->for(auth()->user(), 'talent_signal', $snapshot->id)?->groupBy('subject_key') ?? collect(),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * The requisition's configured pipeline when it has one (so custom stages appear in the
     * journey), else the canonical stage list.
     *
     * @return array<int, array{key: string, label: string, state: string}>
     */
    public function getJourneySteps(): array
    {
        /** @var CandidateApplication $record */
        $record = $this->getRecord();
        $isTerminal = in_array($record->status->value, ['rejected', 'dropout'], true);

        $state = fn (int $position, int $current): string => match (true) {
            $position < $current => 'completed',
            $position === $current => $isTerminal ? 'terminal' : 'current',
            default => 'upcoming',
        };

        $pipeline = $record->pipeline_stage_id !== null ? $record->requisition?->pipelineStages()->get() : null;

        if ($pipeline !== null && $pipeline->isNotEmpty()) {
            $currentPosition = (int) $pipeline->search(fn (RequisitionPipelineStage $stage) => $stage->id === $record->pipeline_stage_id);

            return $pipeline->values()
                ->map(fn (RequisitionPipelineStage $stage, int $position) => ['key' => $stage->code, 'label' => $stage->name, 'state' => $state($position, $currentPosition)])
                ->all();
        }

        $currentOrder = $record->current_stage->order();

        return collect(CandidateStage::cases())
            ->map(fn (CandidateStage $stage) => ['key' => $stage->value, 'label' => $stage->label(), 'state' => $state($stage->order(), $currentOrder)])
            ->all();
    }

    /**
     * A single reverse-chronological feed merging every event type touching this application —
     * built by CandidateTimelineService, which reads each existing source table plus the unified
     * timeline's own events (notes, portal, referrals, self-scheduling) and never computes a new
     * fact.
     *
     * @return Collection<int, array{icon: string, color: string, title: string, subtitle: ?string, meta: ?string, at: Carbon}>
     */
    public function getTimeline(): Collection
    {
        /** @var CandidateApplication $record */
        $record = $this->getRecord();

        return app(CandidateTimelineService::class)->forApplication($record);
    }

    /**
     * Invites the candidate to pick their own interview slot (Phase 4 self-scheduling) and shows
     * the temporary signed link to share. The candidate also sees the invitation in the portal.
     */
    public function inviteToSelfScheduleAction(): Action
    {
        return Action::make('inviteToSelfSchedule')
            ->label('Invite to self-schedule')
            ->icon('heroicon-o-calendar')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->status === ApplicationStatus::Active
                && (bool) auth()->user()?->can('interview-slots.manage')
                && (bool) auth()->user()?->can('update', $this->getRecord()))
            ->schema([
                Select::make('interviewer_id')
                    ->label('Only this interviewer\'s slots')
                    ->options(fn (): array => Interviewer::selectOptions())
                    ->placeholder('Any interviewer')
                    ->searchable(),
                TextInput::make('round_name')->maxLength(255),
            ])
            ->action(function (array $data): void {
                /** @var CandidateApplication $record */
                $record = $this->getRecord();
                $scheduling = app(InterviewSchedulingService::class);
                $interviewer = filled($data['interviewer_id'] ?? null) ? Employee::query()->whereKey(array_keys(Interviewer::selectOptions()))->find($data['interviewer_id']) : null;

                $invitation = InterviewsTable::guarded('Invitation could not be created', fn () => $scheduling->invite($record, auth()->user()?->employee, $interviewer, $data['round_name'] ?? null));

                Notification::make()
                    ->title('Self-scheduling invitation created')
                    ->body("Share this link with the candidate (valid until {$invitation->expires_at->format('d M Y')}). It also appears in their candidate portal:\n".$scheduling->signedLinkFor($invitation))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public function scheduleInterviewAction(): Action
    {
        return Action::make('scheduleInterview')
            ->label('Schedule Interview')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('interviews.manage'))
            ->schema(fn (): array => InterviewForm::schedulingFields($this->getRecord()))
            ->action(function (array $data): void {
                /** @var CandidateApplication $record */
                $record = $this->getRecord();

                InterviewsTable::guarded('Interview could not be scheduled', fn () => app(InterviewService::class)->schedule($record, $data, auth()->user()?->employee));

                Notification::make()->title('Interview scheduled')->success()->send();
            });
    }

    /**
     * Selects the candidate from their latest completed, non-rejected interview round — the same
     * InterviewService::selectCandidate() decision as the Interviews table's action.
     */
    public function selectCandidateAction(): Action
    {
        return Action::make('selectCandidate')
            ->label('Select Candidate')
            ->color('success')
            ->icon('heroicon-o-trophy')
            ->requiresConfirmation()
            ->modalDescription('Moves the application to the Selected stage based on its latest completed interview.')
            ->visible(function (): bool {
                $interview = $this->latestSelectableInterview();

                return $interview !== null && InterviewsTable::canSelectCandidate($interview);
            })
            ->action(function (): void {
                $interview = $this->latestSelectableInterview();

                abort_if($interview === null, 404);

                InterviewsTable::performSelectCandidate($interview);
            });
    }

    public function addFollowupAction(): Action
    {
        return Action::make('addFollowup')
            ->label('Add Follow-up')
            ->icon('heroicon-o-bell-alert')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('followups.manage'))
            ->schema([
                Select::make('followup_type')
                    ->options(collect(FollowupType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                    ->required(),
                DateTimePicker::make('followup_date')->required(),
                Textarea::make('remarks'),
            ])
            ->action(function (array $data): void {
                /** @var CandidateApplication $record */
                $record = $this->getRecord();

                RecruitmentFollowup::query()->create([
                    'candidate_application_id' => $record->id,
                    'recruiter_id' => $record->recruiter_id,
                    'followup_type' => $data['followup_type'],
                    'followup_date' => $data['followup_date'],
                    'status' => 'pending',
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => Filament::auth()->user()?->employee_id,
                ]);

                Notification::make()->title('Follow-up added')->success()->send();
            });
    }

    public function updateNextFollowupAction(): Action
    {
        return Action::make('updateNextFollowup')
            ->label('Update Follow-up')
            ->icon('heroicon-o-pencil')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getRecord()))
            ->schema([
                DateTimePicker::make('next_followup_at')
                    ->default(fn () => $this->getRecord()->next_followup_at),
                Textarea::make('remarks')
                    ->default(fn () => $this->getRecord()->remarks),
            ])
            ->action(function (array $data): void {
                $this->getRecord()->update([
                    'next_followup_at' => $data['next_followup_at'],
                    'remarks' => $data['remarks'] ?? null,
                ]);

                Notification::make()->title('Follow-up updated')->success()->send();
            });
    }

    private function latestSelectableInterview(): ?Interview
    {
        /** @var CandidateApplication $record */
        $record = $this->getRecord();

        return $record->interviews()
            ->orderByDesc('round_number')
            ->orderByDesc('scheduled_at')
            ->get()
            ->first(fn (Interview $interview): bool => app(InterviewService::class)->canSelectCandidateFrom($interview));
    }
}
