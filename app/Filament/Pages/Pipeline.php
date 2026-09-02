<?php

namespace App\Filament\Pages;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\OfferStatus;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\RecruitmentActionCenterService;
use App\Services\RecruitmentAnalyticsService;
use App\Services\StageTransitionService;
use BackedEnum;
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

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('candidates.viewAny');
    }

    /**
     * @return array<int, array{key: string, label: string, stages: array<int, CandidateStage>, dragStage: CandidateStage|null}>
     */
    public function getColumns(): array
    {
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
            'interviews_today' => Interview::query()
                ->whereDate('scheduled_at', today())
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
        return RecruitmentRequisition::query()->orderBy('code')->get()->map(fn (RecruitmentRequisition $r) => ['value' => $r->id, 'label' => $r->code])->all();
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function recruiterOptions(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return CandidateApplication::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->with('recruiter')
            ->get()
            ->pluck('recruiter')
            ->filter()
            ->unique('id')
            ->map(fn ($recruiter) => ['value' => $recruiter->id, 'label' => $recruiter->fullName()])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, CandidateStage>  $stages
     * @return array{applications: Collection<int, CandidateApplication>, total: int, conversion: float|null}
     */
    public function getCardsFor(array $stages): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);
        $stageValues = array_map(fn (CandidateStage $s) => $s->value, $stages);

        $query = CandidateApplication::query()
            ->whereIn('current_stage', $stageValues)
            ->where('status', ApplicationStatus::Active)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->when($this->requisitionId, fn (Builder $q) => $q->where('requisition_id', $this->requisitionId))
            ->when($this->recruiterId, fn (Builder $q) => $q->where('recruiter_id', $this->recruiterId))
            ->when($this->priorityFilter, fn (Builder $q) => $q->where('priority', $this->priorityFilter))
            ->with(['candidate:id,full_name', 'requisition:id,code', 'recruiter:id,first_name,last_name']);

        $total = $query->count();
        $applications = $query->orderByDesc('last_activity_at')->limit(self::CARDS_PER_COLUMN)->get();

        $this->attachStageAgeAndFollowup($applications);

        $sourcedCount = CandidateApplication::query()
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

        $latestStageChange = CandidateStageHistory::query()
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
            $application->setAttribute('stage_age_days', $reachedAt !== null ? (int) now()->diffInDays($reachedAt) : null);
            $application->setAttribute('next_followup', $nextFollowups->get($application->id)?->followup_date);
        }
    }

    public function getColumnListUrl(string $stageKey): string
    {
        $column = collect($this->getColumns())->firstWhere('key', $stageKey);
        $firstStage = $column['stages'][0] ?? null;

        return CandidateApplicationResource::getUrl('index', [
            'tableFilters' => ['current_stage' => ['value' => $firstStage?->value]],
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
     * Drag-and-drop handler for the 8 unambiguous columns (see class docblock) — same
     * authorization + StageTransitionService call as moveApplicationAction()'s modal.
     */
    public function handleSort(int $id, int $position, string $columnKey): void
    {
        $column = collect($this->getColumns())->firstWhere('key', $columnKey);

        if (($column['dragStage'] ?? null) === null) {
            return;
        }

        $application = CandidateApplication::query()->findOrFail($id);

        abort_unless((bool) auth()->user()?->can('transitionStage', $application), 403);

        app(StageTransitionService::class)->transitionTo(
            $application,
            $column['dragStage'],
            auth()->user()?->employee,
        );

        Notification::make()->title('Stage updated')->success()->send();
    }

    public function moveApplicationAction(): Action
    {
        return Action::make('moveApplication')
            ->label('Move to…')
            ->icon('heroicon-o-arrow-right-circle')
            ->schema(function (array $arguments) {
                $application = CandidateApplication::query()->findOrFail($arguments['applicationId']);

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

                app(StageTransitionService::class)->transitionTo(
                    $application,
                    CandidateStage::from($data['stage']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                );

                Notification::make()->title('Stage updated')->success()->send();
            });
    }
}
