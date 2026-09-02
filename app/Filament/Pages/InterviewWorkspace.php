<?php

namespace App\Filament\Pages;

use App\Enums\FeedbackRecommendation;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The Interview Calendar / scheduling workspace (Stage 4, Module 3) — no calendar view existed
 * for interviews before this (only a flat resource table). Today/Tomorrow/This Week/List views
 * plus a Calendar view (month grid adapted from FollowUpCalendar's existing hand-rolled pattern,
 * narrowed to interviews only). Quick actions call InterviewsTable's extracted performX() mutation
 * helpers (performConfirm()/performReschedule()/etc) rather than a second implementation — one
 * InterviewService/business-logic path, two surfaces, each resolving $record its own way (table
 * row binding there, action arguments here, since this page lists many interviews at once).
 */
class InterviewWorkspace extends Page
{
    protected string $view = 'filament.pages.interview-workspace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Interview Calendar';

    public string $activeView = 'today';

    public string $selectedDate;

    public string $month;

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
        $this->month = now()->startOfMonth()->toDateString();
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('interviews.manage');
    }

    public function setView(string $view): void
    {
        $this->activeView = $view;
    }

    /**
     * @return array{today: int, confirmed: int, pending_confirmation: int, no_show: int, feedback_pending: int}
     */
    public function getTodaySummary(): array
    {
        $visibleIds = $this->visibleEmployeeIds();

        $todayInterviews = Interview::query()
            ->whereDate('scheduled_at', today())
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->get(['status']);

        $feedbackPending = Interview::query()
            ->where('status', InterviewStatus::Completed)
            ->whereNull('result')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        return [
            'today' => $todayInterviews->count(),
            'confirmed' => $todayInterviews->where('status', InterviewStatus::Confirmed)->count(),
            'pending_confirmation' => $todayInterviews->where('status', InterviewStatus::Scheduled)->count(),
            'no_show' => $todayInterviews->where('status', InterviewStatus::NoShow)->count(),
            'feedback_pending' => $feedbackPending,
        ];
    }

    /**
     * @return Collection<int, Interview>
     */
    public function getInterviewsForActiveView(): Collection
    {
        $visibleIds = $this->visibleEmployeeIds();

        $query = Interview::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation', 'interviewer']);

        match ($this->activeView) {
            'today' => $query->whereDate('scheduled_at', today()),
            'tomorrow' => $query->whereDate('scheduled_at', now()->addDay()->toDateString()),
            'week' => $query->whereBetween('scheduled_at', [now()->startOfWeek(), now()->endOfWeek()]),
            default => null,
        };

        return $query->orderBy('scheduled_at')->get();
    }

    /**
     * These reuse InterviewsTable's extracted performX() mutation helpers rather than a second
     * implementation — InterviewsTable's own Action closures type-hint an auto-injected $record,
     * which only resolves inside a table-row or single-record-page context (like
     * ViewCandidateApplication's reused actions); this page lists many interviews with no single
     * bound record, so the record here is resolved from the action's arguments instead, then
     * handed to the exact same mutation helper.
     */
    public function confirmAction(): Action
    {
        return Action::make('confirm')
            ->color('success')
            ->icon('heroicon-o-check')
            ->action(fn (array $arguments) => InterviewsTable::performConfirm(Interview::query()->findOrFail($arguments['record'])));
    }

    public function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->color('warning')
            ->icon('heroicon-o-calendar')
            ->schema([
                DateTimePicker::make('scheduled_at')->required(),
            ])
            ->action(fn (array $arguments, array $data) => InterviewsTable::performReschedule(Interview::query()->findOrFail($arguments['record']), $data));
    }

    public function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Complete')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->schema([
                Select::make('result')
                    ->options(collect(InterviewResult::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()]))
                    ->live()
                    ->required(),
                Select::make('rejection_reason_id')
                    ->label('Rejection Reason')
                    ->options(fn () => RecruitmentRejectionReason::query()->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (Get $get) => $get('result') === InterviewResult::Rejected->value),
            ])
            ->action(fn (array $arguments, array $data) => InterviewsTable::performComplete(Interview::query()->findOrFail($arguments['record']), $data));
    }

    public function noShowAction(): Action
    {
        return Action::make('noShow')
            ->label('No Show')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->requiresConfirmation()
            ->action(fn (array $arguments) => InterviewsTable::performNoShow(Interview::query()->findOrFail($arguments['record'])));
    }

    public function addFeedbackAction(): Action
    {
        return Action::make('addFeedback')
            ->label('Add Feedback')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->schema([
                Select::make('interviewer_id')
                    ->label('Interviewer')
                    ->options(fn () => Employee::query()->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()]))
                    ->default(fn () => Filament::auth()->user()?->employee_id)
                    ->searchable()
                    ->required(),
                TextInput::make('score')->numeric()->minValue(1)->maxValue(10),
                Select::make('recommendation')
                    ->options(collect(FeedbackRecommendation::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()]))
                    ->required(),
                Textarea::make('feedback')->required()->columnSpanFull(),
            ])
            ->action(function (array $arguments, array $data): void {
                $interview = Interview::query()->findOrFail($arguments['interviewId']);

                abort_unless((bool) auth()->user()?->can('interviews.manage'), 403);

                $interview->feedback()->create($data);

                Notification::make()->title('Feedback added')->success()->send();
            });
    }

    public function interviewEditUrl(Interview $interview): string
    {
        return InterviewResource::getUrl('edit', ['record' => $interview]);
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
     * @return Collection<string, int>
     */
    public function getInterviewCountsInMonth(): Collection
    {
        $monthStart = CarbonImmutable::parse($this->month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $visibleIds = $this->visibleEmployeeIds();

        return Interview::query()
            ->whereBetween('scheduled_at', [$monthStart, $monthEnd])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->get(['scheduled_at'])
            ->countBy(fn (Interview $interview) => $interview->scheduled_at->toDateString());
    }

    /**
     * @return Collection<int, Interview>
     */
    public function getInterviewsForSelectedDate(): Collection
    {
        $visibleIds = $this->visibleEmployeeIds();

        return Interview::query()
            ->whereDate('scheduled_at', $this->selectedDate)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation', 'interviewer'])
            ->orderBy('scheduled_at')
            ->get();
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
