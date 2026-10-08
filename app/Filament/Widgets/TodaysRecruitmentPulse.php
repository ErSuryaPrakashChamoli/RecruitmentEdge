<?php

namespace App\Filament\Widgets;

use App\Enums\TargetMetric;
use App\Filament\Widgets\Concerns\AuthorizesWidget;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Services\HierarchyService;
use App\Services\Metrics\MetricPeriod;
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
    use AuthorizesWidget, InteractsWithPageFilters, ResolvesDashboardPeriod;

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
            $today = MetricPeriod::day();

            return [$today->from, $today->to->endOfDay()];
        }

        return $this->resolvePeriod();
    }

    public function isTodayOnly(): bool
    {
        [$start, $end] = $this->getPulsePeriod();

        $today = MetricPeriod::now()->toDateString();

        return $start->toDateString() === $today && $end->toDateString() === $today;
    }

    public function getPeriodLabel(): string
    {
        [$start, $end] = $this->getPulsePeriod();

        if ($start->isSameDay($end)) {
            return $start->toDateString() === MetricPeriod::now()->toDateString() ? 'Today' : $start->format('d M Y');
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
        $period = MetricPeriod::between($start, $end);
        $today = MetricPeriod::day();
        $todayStart = $today->from;
        $todayEnd = $today->to->endOfDay();

        // Phase 8.5 (§17): achievement compares actual with target only for the recruiters who have
        // a target — a recruiter without one is "no target", never a target of 0 that inflates the
        // team's achievement. Actuals are read for all recruiters at once (PF-9).
        $rows = collect(self::PULSE_METRICS)->map(function (TargetMetric $metric) use ($recruiters, $metrics, $targets, $start, $end, $period, $today) {
            $todayActuals = $metrics->actualsFor($recruiters->pluck('id'), $metric, $today);
            $periodActuals = $metrics->actualsFor($recruiters->pluck('id'), $metric, $period);
            $periodTarget = null;
            $actualAgainstTarget = 0;

            foreach ($recruiters as $recruiter) {
                $target = $targets->resolveForRange($recruiter, $metric, $start, $end);

                if ($target !== null) {
                    $periodTarget = ($periodTarget ?? 0) + $target;
                    $actualAgainstTarget += $periodActuals[$recruiter->id] ?? 0;
                }
            }

            return [
                'label' => $metric->label(),
                'today' => array_sum($todayActuals),
                'actual' => array_sum($periodActuals),
                'target' => $periodTarget,
                'achievement' => $periodTarget !== null && $periodTarget > 0 ? round($actualAgainstTarget / $periodTarget * 100, 1) : null,
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
