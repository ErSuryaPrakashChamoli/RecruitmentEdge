<?php

namespace App\Filament\Widgets;

use App\Enums\FollowupStatus;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Models\RecruitmentFollowup;
use App\Services\HierarchyService;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Section 5/6's follow-up calendar: a month grid on the left (marked with follow-up, interview
 * and joining badges, overdue follow-ups highlighted) and, on the right, the follow-ups,
 * interviews and joinings for whichever date is clicked. Scoped to the viewer's hierarchy — or the
 * recruiter chosen in the dashboard's recruiter filter — like every other dashboard widget. The
 * period filter is deliberately not applied: the calendar has its own month navigation.
 */
class FollowUpCalendar extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.follow-up-calendar';

    protected int|string|array $columnSpan = 'full';

    public string $selectedDate;

    public string $month;

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
        $this->month = now()->startOfMonth()->toDateString();
    }

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
     * Weeks run Monday to Sunday, matching the rest of this app's reporting periods.
     *
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
        [$monthStart, $monthEnd] = $this->monthBounds();
        $visibleIds = $this->visibleEmployeeIds();

        return Interview::query()
            ->whereBetween('scheduled_at', [$monthStart, $monthEnd])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)
            ))
            ->get(['scheduled_at'])
            ->countBy(fn (Interview $interview) => $interview->scheduled_at->toDateString());
    }

    /**
     * @return Collection<string, int>
     */
    public function getJoiningCountsInMonth(): Collection
    {
        [$monthStart, $monthEnd] = $this->monthBounds();
        $visibleIds = $this->visibleEmployeeIds();

        return CandidateJoining::query()
            ->whereBetween('expected_doj', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)
            ))
            ->get(['expected_doj'])
            ->countBy(fn (CandidateJoining $joining) => $joining->expected_doj->toDateString());
    }

    /**
     * @return Collection<int, Interview>
     */
    public function getInterviewsForSelectedDate(): Collection
    {
        $visibleIds = $this->visibleEmployeeIds();

        return Interview::query()
            ->whereDate('scheduled_at', $this->selectedDate)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)
            ))
            ->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation'])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * @return Collection<int, CandidateJoining>
     */
    public function getJoiningsForSelectedDate(): Collection
    {
        $visibleIds = $this->visibleEmployeeIds();

        return CandidateJoining::query()
            ->whereDate('expected_doj', $this->selectedDate)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)
            ))
            ->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation'])
            ->orderBy('expected_doj')
            ->get();
    }

    /**
     * Per-date follow-up counts for the month (cancelled ones excluded), with how many of those are
     * still pending past their due time so the grid can highlight overdue days.
     *
     * @return Collection<string, array{total: int, overdue: int}>
     */
    public function getFollowupCountsInMonth(): Collection
    {
        [$monthStart, $monthEnd] = $this->monthBounds();

        return $this->followupsQuery()
            ->whereBetween('followup_date', [$monthStart, $monthEnd])
            ->get(['id', 'followup_date', 'status'])
            ->groupBy(fn (RecruitmentFollowup $followup) => $followup->followup_date->toDateString())
            ->map(fn (Collection $followups) => [
                'total' => $followups->count(),
                'overdue' => $followups->filter(fn (RecruitmentFollowup $followup) => $followup->isOverdue())->count(),
            ]);
    }

    /**
     * @return Collection<int, RecruitmentFollowup>
     */
    public function getFollowupsForSelectedDate(): Collection
    {
        return $this->followupsQuery()
            ->whereDate('followup_date', $this->selectedDate)
            ->with(['candidateApplication.candidate', 'recruiter'])
            ->orderBy('followup_date')
            ->get();
    }

    /**
     * Follow-ups are owned by their recruiter_id, so hierarchy scoping is a direct whereIn rather
     * than going through the application like interviews/joinings.
     *
     * @return Builder<RecruitmentFollowup>
     */
    private function followupsQuery(): Builder
    {
        $visibleIds = $this->visibleEmployeeIds();

        return RecruitmentFollowup::query()
            ->where('status', '!=', FollowupStatus::Cancelled)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthBounds(): array
    {
        $monthStart = CarbonImmutable::parse($this->month)->startOfMonth();

        return [$monthStart, $monthStart->endOfMonth()];
    }

    /**
     * Honours the dashboard's recruiter filter via ResolvesDashboardPeriod::filteredUser().
     *
     * @return Collection<int, int>|null
     */
    private function visibleEmployeeIds(): ?Collection
    {
        return app(HierarchyService::class)->visibleEmployeeIdsFor($this->filteredUser());
    }
}
