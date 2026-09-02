<?php

namespace App\Filament\Widgets;

use App\Enums\TargetMetric;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\RecruiterDailyMetricsService;
use App\Services\RecruitmentAnalyticsService;
use App\Services\TargetResolutionService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Section 6's pulse table (Target/Actual/Achievement for Today and MTD), summed across every
 * recruiter visible to the viewer — a genuine team rollup, not a single recruiter's numbers.
 * Shortlisted and Turn-up % (Section 9) are appended alongside the target-driven metrics: turn-up
 * has no configurable target (it's a ratio, not a quota), so it carries a null target/achievement
 * shape instead of forcing a fake target through TargetResolutionService.
 */
class TodaysRecruitmentPulse extends Widget
{
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
        TargetMetric::Shortlisted,
        TargetMetric::Interviews,
        TargetMetric::Offers,
        TargetMetric::Joining,
    ];

    /**
     * @return Collection<int, array{label: string, today: int|string, mtd: int|string, target: int|null, achievement: float|null}>
     */
    public function getRows(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $recruiterIds = CandidateApplication::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('recruiter_id', $visibleIds))
            ->distinct()
            ->pluck('recruiter_id');

        $recruiters = Employee::query()->whereIn('id', $recruiterIds)->get();

        $metrics = app(RecruiterDailyMetricsService::class);
        $targets = app(TargetResolutionService::class);

        $today = now();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $rows = collect(self::PULSE_METRICS)->map(function (TargetMetric $metric) use ($recruiters, $metrics, $targets, $today, $monthStart, $monthEnd) {
            $todayActual = 0;
            $mtdActual = 0;
            $mtdTarget = 0;

            foreach ($recruiters as $recruiter) {
                $todayActual += $metrics->actualFor($recruiter, $metric, $today, $today);
                $mtdActual += $metrics->actualFor($recruiter, $metric, $monthStart, $monthEnd);
                $mtdTarget += $targets->resolveForRange($recruiter, $metric, $monthStart, $monthEnd) ?? 0;
            }

            return [
                'label' => $metric->label(),
                'today' => $todayActual,
                'mtd' => $mtdActual,
                'target' => $mtdTarget,
                'achievement' => $mtdTarget > 0 ? round($mtdActual / $mtdTarget * 100, 1) : null,
            ];
        });

        $analytics = app(RecruitmentAnalyticsService::class);
        $todayTurnUp = $analytics->turnUpAnalysis($today->copy()->startOfDay(), $today->copy()->endOfDay(), $user);
        $mtdTurnUp = $analytics->turnUpAnalysis($monthStart, $monthEnd, $user);

        return $rows->push([
            'label' => 'Turn-up %',
            'today' => "{$todayTurnUp['turnups']}/{$todayTurnUp['lineups']}",
            'mtd' => "{$mtdTurnUp['turnups']}/{$mtdTurnUp['lineups']}",
            'target' => null,
            'achievement' => $mtdTurnUp['turnup_percent'],
        ]);
    }
}
