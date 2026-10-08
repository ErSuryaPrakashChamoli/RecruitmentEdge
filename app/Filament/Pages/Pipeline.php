<?php

namespace App\Filament\Pages;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\OfferStatus;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateApplications\Tables\CandidateApplicationsTable;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRejectionReason;
use App\Models\RequisitionPipelineStage;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\RecruitmentActionCenterService;
use App\Services\RecruitmentAnalyticsService;
use App\Services\StageTransitionService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * A Kanban-style view of the same candidate_applications data CandidateApplicationsTable already
 * shows as a flat list — same StageTransitionService, same hierarchy scoping, same records, just
 * grouped visually by stage. Columns condense CandidateStage's 18 granular values into the
 * spec's ~11 groups (e.g. every Interview* stage renders under one "Interview" column) so the
 * board stays usable; a card's "Move to..." action still targets the exact granular stage.
 *
 * Drag-and-drop (Stage 4) uses Livewire 4's native wire:sort/wire:sort:group, but only between the
 * 8 columns that map to exactly one CandidateStage — the 3 multi-stage columns (Interview/Offer/
 * Joined-group) stay click-only via the existing "Move to..." modal, since a single target stage
 * can't be inferred from a drop into a group of 3-6 granular stages. handleSort() calls the exact
 * same authorization check and StageTransitionService::transitionTo() the modal action already
 * uses — one write path, two ways to reach it.
 *
 * Configurable pipelines (Phase 4.1): when the board is scoped to one requisition that has a
 * configured pipeline snapshot, the columns ARE that requisition's own stages (one column per
 * RequisitionPipelineStage, every column droppable) and drops go through
 * StageTransitionService::moveToStage(), which enforces the pipeline's transition rules. Across
 * several requisitions — whose pipelines may differ — the board deliberately keeps the canonical
 * milestone columns every configured stage maps onto, rather than merging incompatible stage
 * lists; the view explains this and invites choosing a requisition.
 *
 * The board shows Active applications by default; the status filter lets On Hold / Rejected /
 * Dropout applications be viewed too, but those boards are read-only (no drag-and-drop or card
 * actions) since StageTransitionService only moves or closes Active applications.
 */
class Pipeline extends Page
{
    protected string $view = 'filament.pages.pipeline';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?int $navigationSort = 1;

    private const int CARDS_PER_COLUMN = 15;

    public ?int $requisitionId = null;

    public ?int $recruiterId = null;

    public ?string $priorityFilter = null;

    public ?int $departmentId = null;

    public string $statusFilter = 'active';

    /**
     * Phase 8.9 (P89-PERF-010): the scoped application count every column's conversion divides by,
     * computed once per request instead of once per column (not a Livewire property — never sent to
     * the browser, recomputed on the next request).
     */
    protected ?int $sourcedCount = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('candidates.viewAny');
    }

    /**
     * @return array<int, array{key: string, label: string, stages: array<int, CandidateStage>, dragStage: CandidateStage|null, pipelineStageId?: int|null}>
     */
    public function getColumns(): array
    {
        $pipeline = $this->configuredPipeline();

        if ($pipeline !== null) {
            return $pipeline->map(fn (RequisitionPipelineStage $stage) => [
                'key' => 'stage-'.$stage->id,
                'label' => $stage->name,
                'stages' => [$stage->milestone],
                'dragStage' => null,
                'pipelineStageId' => $stage->id,
            ])->values()->all();
        }

        return [
            ['key' => 'sourced', 'label' => 'Sourced', 'stages' => [CandidateStage::Sourced], 'dragStage' => CandidateStage::Sourced],
            ['key' => 'contacted', 'label' => 'Contacted', 'stages' => [CandidateStage::ContactAttempted], 'dragStage' => CandidateStage::ContactAttempted],
            ['key' => 'connected', 'label' => 'Connected', 'stages' => [CandidateStage::Connected], 'dragStage' => CandidateStage::Connected],
            ['key' => 'interested', 'label' => 'Interested', 'stages' => [CandidateStage::Interested], 'dragStage' => CandidateStage::Interested],
            ['key' => 'screened', 'label' => 'Screened', 'stages' => [CandidateStage::Screened], 'dragStage' => CandidateStage::Screened],
            ['key' => 'shortlisted', 'label' => 'Shortlisted', 'stages' => [CandidateStage::Shortlisted], 'dragStage' => CandidateStage::Shortlisted],
            ['key' => 'interview', 'label' => 'Interview', 'stages' => [
                CandidateStage::InterviewScheduled, CandidateStage::Interview1, CandidateStage::Interview2, CandidateStage::FinalInterview,
            ], 'dragStage' => null],
            ['key' => 'selected', 'label' => 'Selected', 'stages' => [CandidateStage::Selected], 'dragStage' => CandidateStage::Selected],
            ['key' => 'offer', 'label' => 'Offer', 'stages' => [
                CandidateStage::OfferInitiated, CandidateStage::OfferReleased, CandidateStage::OfferAccepted,
            ], 'dragStage' => null],
            ['key' => 'joining', 'label' => 'Joining', 'stages' => [CandidateStage::JoiningConfirmed], 'dragStage' => CandidateStage::JoiningConfirmed],
            ['key' => 'joined', 'label' => 'Joined', 'stages' => [
                CandidateStage::Joined, CandidateStage::DocumentsCompleted, CandidateStage::OnboardingCompleted,
            ], 'dragStage' => null],
        ];
    }

    /**
     * The selected requisition's current pipeline snapshot, when the board is scoped to exactly
     * one requisition the viewer can see and it has one; otherwise null (canonical columns).
     *
     * @return Collection<int, RequisitionPipelineStage>|null
     */
    public function configuredPipeline(): ?Collection
    {
        if ($this->requisitionId === null) {
            return null;
        }

        $requisition = RecruitmentRequisitionResource::getEloquentQuery()->find($this->requisitionId);

        if ($requisition === null || ! $requisition->hasConfiguredPipeline()) {
            return null;
        }

        $stages = $requisition->pipelineStages()->get();

        return $stages->isEmpty() ? null : $stages;
    }

    /**
     * One line describing which stage set the board is showing (see class docblock).
     */
    public function boardDescription(): string
    {
        if ($this->configuredPipeline() !== null) {
            $requisition = RecruitmentRequisitionResource::getEloquentQuery()->with('pipelineTemplate')->find($this->requisitionId);

            return "Showing {$requisition->code}'s own pipeline".($requisition->pipelineTemplate ? " ({$requisition->pipelineTemplate->name} v{$requisition->pipeline_template_version})" : '').'. Dragging a card applies this pipeline\'s transition rules.';
        }

        return $this->requisitionId === null
            ? 'Showing the standard stages across all requisitions, since each requisition can have its own pipeline. Choose a requisition to see its configured stages.'
            : 'This requisition has no configured pipeline, so the standard stages are shown.';
    }

    /**
     * @return array{open_positions: int, total_candidates: int, in_pipeline: int, interviews_today: int, offers_pending: int, joining_this_week: int}
     */
    public function getSummary(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);
        $analytics = app(RecruitmentAnalyticsService::class);

        $positionHealth = $analytics->positionHealth($user);
        $joiningAnalytics = $analytics->joiningAnalytics(now()->startOfWeek(), now()->endOfWeek(), $user);

        return [
            'open_positions' => $positionHealth->filter(fn (array $row) => $row['remaining'] > 0)->count(),
            'total_candidates' => CandidateApplication::query()
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
                ->count(),
            'in_pipeline' => CandidateApplication::query()
                ->where('status', ApplicationStatus::Active)
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
                ->count(),
            // Phase 8.9 (P89-PERF-010): today's range, so the scheduled_at index serves it.
            'interviews_today' => Interview::query()
                ->where('scheduled_at', '>=', today())
                ->where('scheduled_at', '<', today()->addDay())
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
                ->count(),
            'offers_pending' => Offer::query()
                ->where('status', OfferStatus::Released)
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
                ->count(),
            'joining_this_week' => $joiningAnalytics['next_7_days'],
        ];
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function requisitionOptions(): array
    {
        return RecruitmentRequisitionResource::getEloquentQuery()
            ->orderBy('code')
            ->get(['id', 'code'])
            ->map(fn ($requisition) => ['value' => $requisition->id, 'label' => $requisition->code])
            ->all();
    }

    /**
     * Departments of the requisitions the viewer can see — same hierarchy scope as
     * requisitionOptions(), so the filter never leaks departments outside the viewer's hierarchy.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function departmentOptions(): array
    {
        return Department::query()
            ->whereIn('id', RecruitmentRequisitionResource::getEloquentQuery()->select('department_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department) => ['value' => $department->id, 'label' => $department->name])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function statusOptions(): array
    {
        return collect(ApplicationStatus::cases())
            ->map(fn (ApplicationStatus $status) => ['value' => $status->value, 'label' => $status->label()])
            ->all();
    }

    public function selectedStatus(): ApplicationStatus
    {
        return ApplicationStatus::tryFrom($this->statusFilter) ?? ApplicationStatus::Active;
    }

    /**
     * Drag-and-drop and card actions only apply to Active applications — see class docblock.
     */
    public function isActiveBoard(): bool
    {
        return $this->selectedStatus() === ApplicationStatus::Active;
    }

    public function hasActiveFilters(): bool
    {
        return $this->requisitionId || $this->recruiterId || $this->priorityFilter || $this->departmentId
            || ! $this->isActiveBoard();
    }

    public function clearFilters(): void
    {
        $this->requisitionId = null;
        $this->recruiterId = null;
        $this->priorityFilter = null;
        $this->departmentId = null;
        $this->statusFilter = ApplicationStatus::Active->value;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function recruiterOptions(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        // Phase 8.9 (P89-PERF-010): the recruiters owning at least one scoped application, from one
        // DISTINCT subquery — never every scoped application loaded on each render. Deleted
        // employees keep their attribution (as the recruiter relation does).
        return Employee::withTrashed()
            ->whereIn('id', CandidateApplication::query()
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
                ->select('recruiter_id')
                ->distinct())
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (Employee $recruiter) => ['value' => $recruiter->id, 'label' => $recruiter->fullName()])
            ->values()
            ->all();
    }

    /**
     * Cards for one column: a configured-pipeline column matches its RequisitionPipelineStage,
     * a canonical column matches its CandidateStage values.
     *
     * @param  array{key: string, stages: array<int, CandidateStage>, pipelineStageId?: int|null}  $column
     * @return array{applications: Collection<int, CandidateApplication>, total: int, conversion: float|null}
     */
    public function getCardsFor(array $column): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);
        $pipelineStageId = $column['pipelineStageId'] ?? null;

        $query = CandidateApplication::query()
            ->when(
                $pipelineStageId !== null,
                fn (Builder $q) => $q->where('pipeline_stage_id', $pipelineStageId),
                fn (Builder $q) => $q->whereIn('current_stage', array_map(fn (CandidateStage $s) => $s->value, $column['stages'])),
            )
            ->where('status', $this->selectedStatus())
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->when($this->departmentId, fn (Builder $q) => $q->whereHas('requisition', fn (Builder $r) => $r->where('department_id', $this->departmentId)))
            ->when($this->requisitionId, fn (Builder $q) => $q->where('requisition_id', $this->requisitionId))
            ->when($this->recruiterId, fn (Builder $q) => $q->where('recruiter_id', $this->recruiterId))
            ->when($this->priorityFilter, fn (Builder $q) => $q->where('priority', $this->priorityFilter))
            ->with(['candidate:id,full_name', 'requisition:id,code', 'recruiter:id,first_name,last_name']);

        $total = $query->count();
        $applications = $query->orderByDesc('last_activity_at')->limit(self::CARDS_PER_COLUMN)->get();

        $this->attachStageAgeAndFollowup($applications);

        $sourcedCount = $this->sourcedCount ??= CandidateApplication::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        return [
            'applications' => $applications,
            'total' => $total,
            'conversion' => $sourcedCount > 0 ? round($total / $sourcedCount * 100, 1) : null,
        ];
    }

    /**
     * Batch-fetches stage age (most recent stageHistory row) and next follow-up for every card in
     * a column with 2 queries total, not one query per card — avoids the N+1 a naive per-card
     * lookup would cause.
     *
     * @param  Collection<int, CandidateApplication>  $applications
     */
    private function attachStageAgeAndFollowup(Collection $applications): void
    {
        if ($applications->isEmpty()) {
            return;
        }

        $ids = $applications->pluck('id');

        // Phase 8.5 (DF-7, DF-9): the latest genuine move into a stage — a hold or reactivation does
        // not reset a card's age — measured forwards (Carbon 3 diffs are signed).
        $latestStageChange = CandidateStageHistory::query()->pipelineStageEntries()
            ->whereIn('candidate_application_id', $ids)
            ->orderByDesc('created_at')
            ->get(['candidate_application_id', 'created_at'])
            ->unique('candidate_application_id')
            ->keyBy('candidate_application_id');

        $nextFollowups = RecruitmentFollowup::query()
            ->whereIn('candidate_application_id', $ids)
            ->where('status', 'pending')
            ->orderBy('followup_date')
            ->get(['candidate_application_id', 'followup_date'])
            ->unique('candidate_application_id')
            ->keyBy('candidate_application_id');

        foreach ($applications as $application) {
            $reachedAt = $latestStageChange->get($application->id)?->created_at ?? $application->application_date;
            $application->setAttribute('stage_age_days', $reachedAt !== null ? max(0, (int) $reachedAt->diffInDays(now())) : null);
            $application->setAttribute('next_followup', $nextFollowups->get($application->id)?->followup_date);
        }
    }

    public function getColumnListUrl(string $stageKey): string
    {
        $column = collect($this->getColumns())->firstWhere('key', $stageKey);
        $firstStage = $column['stages'][0] ?? null;

        return CandidateApplicationResource::getUrl('index', [
            'filters' => ['current_stage' => ['value' => $firstStage?->value]],
        ]);
    }

    /**
     * @return Collection<int, array{key: string, label: string, severity: string, message: string}>
     */
    public function getIntelligence(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return app(RecruitmentActionCenterService::class)->alerts($user);
    }

    /**
     * Drag-and-drop handler — same authorization as moveApplicationAction()'s modal. On a
     * configured pipeline every column is a drop target and the move goes through
     * StageTransitionService::moveToStage() (the pipeline's transition rules apply); on the
     * canonical board only the 8 single-stage columns accept drops (see class docblock).
     */
    public function handleSort(int $id, int $position, string $columnKey): void
    {
        $column = collect($this->getColumns())->firstWhere('key', $columnKey);
        $pipelineStageId = $column['pipelineStageId'] ?? null;

        if ($column === null || ($pipelineStageId === null && ($column['dragStage'] ?? null) === null)) {
            return;
        }

        $application = CandidateApplication::query()->findOrFail($id);

        abort_unless((bool) auth()->user()?->can('transitionStage', $application), 403);

        try {
            $service = app(StageTransitionService::class);

            if ($pipelineStageId !== null) {
                $service->moveToStage($application, RequisitionPipelineStage::query()->findOrFail($pipelineStageId), auth()->user()?->employee);
            } else {
                $service->advance($application, $column['dragStage'], auth()->user()?->employee);
            }
        } catch (DomainException $e) {
            Notification::make()->title('Stage could not be updated')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Stage updated')->success()->send();
    }

    public function moveApplicationAction(): Action
    {
        return Action::make('moveApplication')
            ->label('Move to…')
            ->icon('heroicon-o-arrow-right-circle')
            ->schema(function (array $arguments) {
                $application = CandidateApplication::query()->findOrFail($arguments['applicationId']);

                if ($application->pipeline_stage_id !== null) {
                    return CandidateApplicationsTable::pipelineStageFields($application);
                }

                return [
                    Select::make('stage')
                        ->label('New Stage')
                        ->options(collect(CandidateStage::cases())
                            ->filter(fn (CandidateStage $s) => $s->order() >= $application->current_stage->order())
                            ->mapWithKeys(fn (CandidateStage $s) => [$s->value => $s->label()])
                            ->all())
                        ->default($application->current_stage->value)
                        ->required(),
                    Textarea::make('remarks'),
                ];
            })
            ->action(function (array $arguments, array $data): void {
                $application = CandidateApplication::query()->findOrFail($arguments['applicationId']);

                abort_unless((bool) auth()->user()?->can('transitionStage', $application), 403);

                CandidateApplicationsTable::performStageMove($application, $data);
            });
    }

    /**
     * Same authorization (`update`), reason list and StageTransitionService::reject() call as
     * CandidateApplicationsTable::rejectAction(). The card leaves the Active board on re-render.
     */
    public function rejectApplicationAction(): Action
    {
        return Action::make('rejectApplication')
            ->label('Reject')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->schema([
                CandidateApplicationsTable::reasonSelect('reason_id'),
                Textarea::make('remarks'),
            ])
            ->action(function (array $arguments, array $data): void {
                $application = $this->findAuthorizedApplication($arguments);

                CandidateApplicationsTable::performTransition(
                    fn (StageTransitionService $service) => $service->reject(
                        $application,
                        RecruitmentRejectionReason::query()->findOrFail($data['reason_id']),
                        auth()->user()?->employee,
                        $data['remarks'] ?? null,
                    ),
                    'Application rejected',
                );
            });
    }

    /**
     * Same authorization, reason list and StageTransitionService::dropout() call as
     * CandidateApplicationsTable::dropoutAction().
     */
    public function dropoutApplicationAction(): Action
    {
        return Action::make('dropoutApplication')
            ->label('Drop Out')
            ->color('danger')
            ->icon('heroicon-o-arrow-uturn-left')
            ->schema([
                CandidateApplicationsTable::reasonSelect('reason_id'),
                Textarea::make('remarks'),
            ])
            ->action(function (array $arguments, array $data): void {
                $application = $this->findAuthorizedApplication($arguments);

                CandidateApplicationsTable::performTransition(
                    fn (StageTransitionService $service) => $service->dropout(
                        $application,
                        RecruitmentRejectionReason::query()->findOrFail($data['reason_id']),
                        auth()->user()?->employee,
                        $data['remarks'] ?? null,
                    ),
                    'Application marked as dropout',
                );
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findAuthorizedApplication(array $arguments): CandidateApplication
    {
        $application = CandidateApplication::query()->findOrFail($arguments['applicationId'] ?? null);

        abort_unless((bool) auth()->user()?->can('update', $application), 403);

        return $application;
    }
}
