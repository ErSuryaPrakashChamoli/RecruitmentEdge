<?php

namespace App\Filament\Widgets;

use App\Enums\TargetMetric;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Services\HierarchyService;
use App\Services\RecruiterDailyMetricsService;
use App\Services\RecruitmentAnalyticsService;
use App\Services\TargetResolutionService;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Section 6's pulse table (Target / Actual / Achievement for every target metric), summed across
 * every recruiter in scope — the viewer's hierarchy, or the recruiter chosen in the dashboard's
 * recruiter filter. Follows the dashboard period filter (today when none is set); "Today" is
 * always shown alongside so the day's progress stays visible inside a longer period. Turn-up %
 * (Section 9) has no configurable target (it's a ratio, not a quota), so it carries a null
 * target shape instead of forcing a fake target through TargetResolutionService.
 */
class TodaysRecruitmentPulse extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.todays-recruitment-pulse';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<int, TargetMetric>
     */
    private const array PULSE_METRICS = [
        TargetMetric::ProfilesSourced,
        TargetMetric::Calls,
        TargetMetric::ConnectedCalls,
        TargetMetric::InterestedCandidates,
        TargetMetric::Screening,
        TargetMetric::Shortlisted,
        TargetMetric::Interviews,
        TargetMetric::Selections,
        TargetMetric::Offers,
        TargetMetric::Joining,
    ];

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function getPulsePeriod(): array
    {
        if (blank($this->pageFilters['period'] ?? null)) {
            $now = CarbonImmutable::now();

            return [$now->startOfDay(), $now->endOfDay()];
        }

        return $this->resolvePeriod();
    }

    public function isTodayOnly(): bool
    {
        [$start, $end] = $this->getPulsePeriod();

        return $start->isToday() && $end->isToday();
    }

    public function getPeriodLabel(): string
    {
        [$start, $end] = $this->getPulsePeriod();

        if ($start->isSameDay($end)) {
            return $start->isToday() ? 'Today' : $start->format('d M Y');
        }

        return $start->format('d M').' – '.$end->format('d M Y');
    }

    /**
     * @return Collection<int, array{label: string, today: int|string, actual: int|string, target: int|null, achievement: float|null}>
     */
    public function getRows(): Collection
    {
        $scopeUser = $this->filteredUser();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($scopeUser);

        $recruiterIds = CandidateApplication::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('recruiter_id', $visibleIds))
            ->whereNotNull('recruiter_id')
            ->distinct()
            ->pluck('recruiter_id');

        $recruiters = Employee::query()->whereIn('id', $recruiterIds)->get();

        $metrics = app(RecruiterDailyMetricsService::class);
        $targets = app(TargetResolutionService::class);

        [$start, $end] = $this->getPulsePeriod();
        $todayStart = CarbonImmutable::now()->startOfDay();
        $todayEnd = CarbonImmutable::now()->endOfDay();

        $rows = collect(self::PULSE_METRICS)->map(function (TargetMetric $metric) use ($recruiters, $metrics, $targets, $start, $end, $todayStart, $todayEnd) {
            $todayActual = 0;
            $periodActual = 0;
            $periodTarget = 0;

            foreach ($recruiters as $recruiter) {
                $todayActual += $metrics->actualFor($recruiter, $metric, $todayStart, $todayEnd);
                $periodActual += $metrics->actualFor($recruiter, $metric, $start, $end);
                $periodTarget += $targets->resolveForRange($recruiter, $metric, $start, $end) ?? 0;
            }

            return [
                'label' => $metric->label(),
                'today' => $todayActual,
                'actual' => $periodActual,
                'target' => $periodTarget,
                'achievement' => $periodTarget > 0 ? round($periodActual / $periodTarget * 100, 1) : null,
            ];
        });

        $analytics = app(RecruitmentAnalyticsService::class);
        $todayTurnUp = $analytics->turnUpAnalysis($todayStart, $todayEnd, $scopeUser);
        $periodTurnUp = $analytics->turnUpAnalysis($start, $end, $scopeUser);

        return $rows->push([
            'label' => 'Turn-up %',
            'today' => "{$todayTurnUp['turnups']}/{$todayTurnUp['lineups']}",
            'actual' => "{$periodTurnUp['turnups']}/{$periodTurnUp['lineups']}",
            'target' => null,
            'achievement' => $periodTurnUp['turnup_percent'],
        ]);
    }
}
