<?php

namespace App\Filament\Pages;

use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Interviews\Schemas\InterviewForm;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\CandidateApplication;
use App\Models\Interview;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\InterviewService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The Interview Calendar / scheduling workspace (Stage 4, Module 3). Today/Tomorrow/Day/Week/
 * Unconfirmed list views plus a Calendar view (month grid adapted from FollowUpCalendar's existing
 * hand-rolled pattern, narrowed to interviews only) and an interviewer load panel. Quick actions
 * call InterviewsTable's extracted performX() mutation helpers (performConfirm()/
 * performReschedule()/etc) rather than a second implementation — one InterviewService/business-
 * logic path, two surfaces, each resolving $record its own way (table row binding there, action
 * arguments here, since this page lists many interviews at once).
 */
class InterviewWorkspace extends Page
{
    public const int DEFAULT_INTERVIEWER_DAILY_CAPACITY = 4;

    protected string $view = 'filament.pages.interview-workspace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Interview Calendar';

    public string $activeView = 'today';

    public string $selectedDate;

    public string $month;

    public string $weekStart;

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
        $this->month = now()->startOfMonth()->toDateString();
        $this->weekStart = now()->startOfWeek()->toDateString();
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('interviews.manage');
    }

    public function setView(string $view): void
    {
        $this->activeView = $view;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->scheduleInterviewAction(),
        ];
    }

    /**
     * @return array{today: int, confirmed: int, pending_confirmation: int, no_show: int, feedback_pending: int}
     */
    public function getTodaySummary(): array
    {
        $todayInterviews = $this->scopedInterviews()
            ->whereDate('scheduled_at', today())
            ->get(['status']);

        $feedbackPending = $this->scopedInterviews()
            ->where('status', InterviewStatus::Completed)
            ->whereNull('result')
            ->count();

        return [
            'today' => $todayInterviews->count(),
            'confirmed' => $todayInterviews->where('status', InterviewStatus::Confirmed)->count(),
            'pending_confirmation' => $this->unconfirmedInterviewsQuery()->count(),
            'no_show' => $todayInterviews->where('status', InterviewStatus::NoShow)->count(),
            'feedback_pending' => $feedbackPending,
        ];
    }

    /**
     * @return Collection<int, Interview>
     */
    public function getInterviewsForActiveView(): Collection
    {
        $query = $this->activeView === 'unconfirmed'
            ? $this->unconfirmedInterviewsQuery()
            : $this->scopedInterviews();

        $query->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation', 'interviewer']);

        match ($this->activeView) {
            'today' => $query->whereDate('scheduled_at', today()),
            'tomorrow' => $query->whereDate('scheduled_at', now()->addDay()->toDateString()),
            'day' => $query->whereDate('scheduled_at', $this->selectedDate),
            'week' => $query->whereBetween('scheduled_at', $this->weekRange()),
            default => null,
        };

        return $query->orderBy('scheduled_at')->get();
    }

    /**
     * These reuse InterviewsTable's extracted performX() mutation helpers rather than a second
     * implementation — InterviewsTable's own Action closures type-hint an auto-injected $record,
     * which only resolves inside a table-row or single-record-page context (like
     * ViewCandidateApplication's reused actions); this page lists many interviews with no single
     * bound record, so the record here is resolved (hierarchy-scoped) from the action's arguments
     * instead, then handed to the exact same mutation helper.
     */
    public function confirmAction(): Action
    {
        return Action::make('confirm')
            ->color('success')
            ->icon('heroicon-o-check')
            ->action(fn (array $arguments) => InterviewsTable::performConfirm($this->resolveInterview($arguments['record'])));
    }

    public function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->color('warning')
            ->icon('heroicon-o-calendar')
            ->schema(InterviewsTable::rescheduleSchema())
            ->action(fn (array $arguments, array $data) => InterviewsTable::performReschedule($this->resolveInterview($arguments['record']), $data));
    }

    public function holdAction(): Action
    {
        return Action::make('hold')
            ->label('Hold')
            ->color('warning')
            ->icon('heroicon-o-pause-circle')
            ->schema(InterviewsTable::holdSchema())
            ->action(fn (array $arguments, array $data) => InterviewsTable::performHold($this->resolveInterview($arguments['record']), $data));
    }

    public function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Complete')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->modalDescription('At least one feedback entry must be recorded before an interview can be completed.')
            ->schema([
                Select::make('result')
                    ->options(collect(InterviewResult::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()]))
                    ->live()
                    ->required(),
                Select::make('rejection_reason_id')
                    ->label('Rejection Reason')
                    ->options(fn (): array => RecruitmentRejectionReason::groupedActiveOptions())
                    ->searchable()
                    ->required(fn (Get $get) => $get('result') === InterviewResult::Rejected->value)
                    ->visible(fn (Get $get) => $get('result') === InterviewResult::Rejected->value),
            ])
            ->action(fn (array $arguments, array $data) => InterviewsTable::performComplete($this->resolveInterview($arguments['record']), $data));
    }

    public function noShowAction(): Action
    {
        return Action::make('noShow')
            ->label('No Show')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->requiresConfirmation()
            ->action(fn (array $arguments) => InterviewsTable::performNoShow($this->resolveInterview($arguments['record'])));
    }

    public function addFeedbackAction(): Action
    {
        return Action::make('addFeedback')
            ->label('Add Feedback')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->schema(InterviewsTable::feedbackSchema())
            ->action(fn (array $arguments, array $data) => InterviewsTable::performAddFeedback(
                $this->resolveInterview($arguments['interviewId']),
                $data,
            ));
    }

    public function scheduleInterviewAction(): Action
    {
        return Action::make('scheduleInterview')
            ->label('Schedule Interview')
            ->icon('heroicon-o-calendar-days')
            ->schema([
                InterviewForm::applicationSelect(),
                ...InterviewForm::schedulingFields(),
            ])
            ->action(function (array $data): void {
                $application = InterviewForm::scopeApplicationsToViewer(CandidateApplication::query())
                    ->findOrFail($data['candidate_application_id']);

                InterviewsTable::guarded('Interview could not be scheduled', fn () => app(InterviewService::class)->schedule($application, $data, auth()->user()?->employee));

                Notification::make()->title('Interview scheduled')->success()->send();
            });
    }

    public function interviewEditUrl(Interview $interview): string
    {
        return InterviewResource::getUrl('edit', ['record' => $interview]);
    }

    // --- Day / week navigation ---

    public function openDay(string $date): void
    {
        $this->selectedDate = CarbonImmutable::parse($date)->toDateString();
        $this->activeView = 'day';
    }

    public function previousDay(): void
    {
        $this->selectedDate = CarbonImmutable::parse($this->selectedDate)->subDay()->toDateString();
    }

    public function nextDay(): void
    {
        $this->selectedDate = CarbonImmutable::parse($this->selectedDate)->addDay()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->weekStart = CarbonImmutable::parse($this->weekStart)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->weekStart = CarbonImmutable::parse($this->weekStart)->addWeek()->toDateString();
    }

    public function goToCurrentWeek(): void
    {
        $this->weekStart = now()->startOfWeek()->toDateString();
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    public function getWeekDays(): array
    {
        $start = CarbonImmutable::parse($this->weekStart)->startOfDay();

        return array_map(fn (int $offset): CarbonImmutable => $start->addDays($offset), range(0, 6));
    }

    // --- Calendar (month grid, adapted from FollowUpCalendar's existing pattern) ---

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
    }

    public function previousMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month)->subMonthNoOverflow()->toDateString();
    }

    public function nextMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month)->addMonthNoOverflow()->toDateString();
    }

    public function goToToday(): void
    {
        $this->month = now()->startOfMonth()->toDateString();
        $this->selectedDate = now()->toDateString();
    }

    /**
     * @return array<int, array<int, CarbonImmutable|null>>
     */
    public function getCalendarWeeks(): array
    {
        $monthStart = CarbonImmutable::parse($this->month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $weeks = [];
        $week = array_fill(0, $monthStart->dayOfWeekIso - 1, null);

        for ($day = $monthStart; $day->lte($monthEnd); $day = $day->addDay()) {
            $week[] = $day;

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        if ($week !== []) {
            $weeks[] = array_pad($week, 7, null);
        }

        return $weeks;
    }

    /**
     * Per-day, per-status interview counts for the visible month, e.g.
     * ['2026-09-14' => ['scheduled' => 2, 'completed' => 1]].
     *
     * @return Collection<string, Collection<string, int>>
     */
    public function getInterviewStatusCountsInMonth(): Collection
    {
        $monthStart = CarbonImmutable::parse($this->month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        return $this->scopedInterviews()
            ->whereBetween('scheduled_at', [$monthStart, $monthEnd])
            ->get(['scheduled_at', 'status'])
            ->groupBy(fn (Interview $interview) => $interview->scheduled_at->toDateString())
            ->map(fn (Collection $interviews) => $interviews->countBy(fn (Interview $interview) => $interview->status->value));
    }

    /**
     * Literal Tailwind palette classes per status color (see kpi-card.blade.php for why literal
     * palette names are used instead of Filament's semantic color utilities).
     */
    public function statusBadgeClasses(InterviewStatus $status): string
    {
        return match ($status->color()) {
            'success' => 'bg-emerald-500 text-white',
            'info' => 'bg-blue-500 text-white',
            'warning' => 'bg-amber-500 text-white',
            'danger' => 'bg-rose-500 text-white',
            default => 'bg-gray-400 text-white',
        };
    }

    /**
     * @return Collection<int, Interview>
     */
    public function getInterviewsForSelectedDate(): Collection
    {
        return $this->scopedInterviews()
            ->whereDate('scheduled_at', $this->selectedDate)
            ->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation', 'interviewer'])
            ->orderBy('scheduled_at')
            ->get();
    }

    // --- Interviewer load ---

    public function interviewerDailyCapacity(): int
    {
        return max(1, (int) RecruitmentSetting::get('interviewer_daily_capacity', self::DEFAULT_INTERVIEWER_DAILY_CAPACITY));
    }

    /**
     * Interviews per interviewer for the period the active view is showing (the week in Week
     * view, otherwise a single day). Cancelled interviews don't occupy a slot. An interviewer is
     * over-booked when any single day in the period exceeds the daily capacity.
     *
     * @return array{label: string, capacity: int, rows: Collection<int, array{name: string, total: int, peak: int, overbooked: bool}>}
     */
    public function getInterviewerLoad(): array
    {
        [$start, $end] = match ($this->activeView) {
            'week' => $this->weekRange(),
            'today', 'unconfirmed' => [today()->toImmutable()->startOfDay(), today()->toImmutable()->endOfDay()],
            'tomorrow' => [today()->toImmutable()->addDay()->startOfDay(), today()->toImmutable()->addDay()->endOfDay()],
            default => [CarbonImmutable::parse($this->selectedDate)->startOfDay(), CarbonImmutable::parse($this->selectedDate)->endOfDay()],
        };

        $capacity = $this->interviewerDailyCapacity();

        $rows = $this->scopedInterviews()
            ->whereBetween('scheduled_at', [$start, $end])
            ->where('status', '!=', InterviewStatus::Cancelled)
            ->with('interviewer')
            ->get(['id', 'interviewer_id', 'scheduled_at', 'status'])
            ->groupBy('interviewer_id')
            ->map(function (Collection $interviews) use ($capacity): array {
                $peak = $interviews->countBy(fn (Interview $interview) => $interview->scheduled_at->toDateString())->max();

                return [
                    'name' => $interviews->first()->interviewer?->fullName() ?? 'Unassigned',
                    'total' => $interviews->count(),
                    'peak' => $peak,
                    'overbooked' => $peak > $capacity,
                ];
            })
            ->sortByDesc('total')
            ->values();

        return [
            'label' => $start->isSameDay($end) ? $start->format('D, d M Y') : $start->format('d M').' – '.$end->format('d M Y'),
            'capacity' => $capacity,
            'rows' => $rows,
        ];
    }

    /**
     * Upcoming (today onwards) interviews that still await confirmation.
     *
     * @return Builder<Interview>
     */
    private function unconfirmedInterviewsQuery(): Builder
    {
        return $this->scopedInterviews()
            ->whereIn('status', InterviewStatus::unconfirmed())
            ->where('scheduled_at', '>=', today()->startOfDay());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function weekRange(): array
    {
        $start = CarbonImmutable::parse($this->weekStart)->startOfDay();

        return [$start, $start->addDays(6)->endOfDay()];
    }

    /**
     * Hierarchy scoping matching InterviewResource::getEloquentQuery() / InterviewPolicy: visible
     * when either the application's recruiter or the interviewer is in the viewer's hierarchy.
     *
     * @return Builder<Interview>
     */
    private function scopedInterviews(): Builder
    {
        $visibleIds = $this->visibleEmployeeIds();

        return Interview::query()
            ->when($visibleIds !== null, fn (Builder $query) => $query->where(function (Builder $q) use ($visibleIds): void {
                $q->whereIn('interviewer_id', $visibleIds)
                    ->orWhereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds));
            }));
    }

    private function resolveInterview(int|string $id): Interview
    {
        return $this->scopedInterviews()->findOrFail($id);
    }

    /**
     * @return Collection<int, int>|null
     */
    private function visibleEmployeeIds(): ?Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return app(HierarchyService::class)->visibleEmployeeIdsFor($user);
    }
}
