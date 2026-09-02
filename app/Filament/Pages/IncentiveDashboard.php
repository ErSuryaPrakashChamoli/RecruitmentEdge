<?php

namespace App\Filament\Pages;

use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use App\Filament\Widgets\IncentiveDashboardStats;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\TargetResolutionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Stage 4, Module 5: a personal scorecard (the viewer's own current-period calculations, with
 * slab-progress navigation over the already-ordered RecruitmentIncentiveRule::slabs()) plus, for
 * anyone who manages more than themselves, a Team Incentive view built on the same hierarchy-scoped
 * "distinct recruiter_id" pattern RecruiterLeaderboardWidget already uses. Every figure reuses
 * RecruiterIncentiveCalculation::effectiveAmount()/TargetResolutionService::resolveForRange() —
 * the only genuinely new computation is the two-period growth diff for the "Highest Growth" badge.
 */
class IncentiveDashboard extends Page
{
    protected string $view = 'filament.pages.incentive-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Incentives';

    protected static ?string $navigationLabel = 'Incentive Dashboard';

    protected static ?int $navigationSort = -1;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('incentives.view');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            IncentiveDashboardStats::class,
        ];
    }

    /**
     * @return Collection<int, array{calculation: RecruiterIncentiveCalculation, target: int|null, adjustmentsTotal: float, final: float, slabProgress: array{current: RecruitmentIncentiveSlab, next: RecruitmentIncentiveSlab|null, remaining: float|null, progressPct: float, potentialAdditional: float|null}|null}>
     */
    public function getMyScorecard(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        if ($user->employee_id === null) {
            return collect();
        }

        return RecruiterIncentiveCalculation::query()
            ->where('employee_id', $user->employee_id)
            ->whereDate('period_start', now()->startOfMonth())
            ->with(['incentiveRule.slabs', 'incentiveSlab', 'adjustments', 'employee', 'candidate'])
            ->get()
            ->map(fn (RecruiterIncentiveCalculation $calculation) => [
                'calculation' => $calculation,
                'target' => $this->resolveTargetFor($calculation),
                'adjustmentsTotal' => (float) $calculation->adjustments->sum('amount_delta'),
                'final' => $calculation->effectiveAmount(),
                'slabProgress' => $this->slabProgressFor($calculation),
            ]);
    }

    private function resolveTargetFor(RecruiterIncentiveCalculation $calculation): ?int
    {
        $rule = $calculation->incentiveRule;

        if ($rule === null || $rule->achievement_metric === null) {
            return null;
        }

        return app(TargetResolutionService::class)->resolveForRange(
            $calculation->employee,
            $rule->achievement_metric,
            $calculation->period_start,
            $calculation->period_end,
        );
    }

    /**
     * @return array{current: RecruitmentIncentiveSlab, next: RecruitmentIncentiveSlab|null, remaining: float|null, progressPct: float, potentialAdditional: float|null}|null
     */
    private function slabProgressFor(RecruiterIncentiveCalculation $calculation): ?array
    {
        $slabs = $calculation->incentiveRule?->slabs;

        if ($slabs === null || $slabs->isEmpty() || $calculation->achievement === null) {
            return null;
        }

        $currentIndex = $slabs->search(fn (RecruitmentIncentiveSlab $slab) => $slab->id === $calculation->incentive_slab_id);

        if ($currentIndex === false) {
            return null;
        }

        $current = $slabs[$currentIndex];
        $next = $slabs[$currentIndex + 1] ?? null;
        $achievement = (float) $calculation->achievement;
        $bandMin = (float) $current->achievement_min;
        $bandMax = $current->achievement_max !== null
            ? (float) $current->achievement_max
            : ($next !== null ? (float) $next->achievement_min : $bandMin);

        return [
            'current' => $current,
            'next' => $next,
            'remaining' => $next !== null ? max(0.0, (float) $next->achievement_min - $achievement) : null,
            'progressPct' => $bandMax > $bandMin ? min(100, max(0, ($achievement - $bandMin) / ($bandMax - $bandMin) * 100)) : 100.0,
            'potentialAdditional' => $next !== null ? (float) $next->amount - (float) $current->amount : null,
        ];
    }

    /**
     * Same gate RecruiterLeaderboardWidget::isIndividualView() already uses, inverted — anyone
     * with more than themselves in their visible hierarchy manages a team.
     */
    public function isTeamView(): bool
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return $visibleIds === null || $visibleIds->count() > 1;
    }

    /**
     * @return Collection<int, array{recruiter: Employee, amount: float, achievement: float|null, growth: float, status: string}>
     */
    public function getTeamIncentives(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $recruiterIds = CandidateApplication::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('recruiter_id', $visibleIds))
            ->distinct()
            ->pluck('recruiter_id');

        if ($recruiterIds->isEmpty()) {
            return collect();
        }

        $recruiters = Employee::query()->whereIn('id', $recruiterIds)->get();

        $thisPeriod = $this->calculationsByRecruiterFor(now()->startOfMonth(), $recruiterIds);
        $lastPeriod = $this->calculationsByRecruiterFor(now()->subMonthNoOverflow()->startOfMonth(), $recruiterIds);

        return $recruiters
            ->map(function (Employee $recruiter) use ($thisPeriod, $lastPeriod) {
                $current = $thisPeriod->get($recruiter->id, collect());
                $previous = $lastPeriod->get($recruiter->id, collect());
                $statuses = $current->pluck('status')->unique();

                return [
                    'recruiter' => $recruiter,
                    'amount' => $current->sum(fn (RecruiterIncentiveCalculation $c) => $c->effectiveAmount()),
                    'achievement' => $current->whereNotNull('achievement')->avg(fn (RecruiterIncentiveCalculation $c) => (float) $c->achievement),
                    'growth' => $current->sum(fn (RecruiterIncentiveCalculation $c) => $c->effectiveAmount())
                        - $previous->sum(fn (RecruiterIncentiveCalculation $c) => $c->effectiveAmount()),
                    'status' => match (true) {
                        $current->isEmpty() => 'No Activity',
                        $statuses->count() > 1 => 'Mixed',
                        default => $statuses->first()->label(),
                    },
                ];
            })
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * @param  Collection<int, int>  $recruiterIds
     * @return Collection<int, Collection<int, RecruiterIncentiveCalculation>>
     */
    private function calculationsByRecruiterFor(Carbon $periodStart, Collection $recruiterIds): Collection
    {
        return RecruiterIncentiveCalculation::query()
            ->whereIn('employee_id', $recruiterIds)
            ->whereDate('period_start', $periodStart)
            ->with('adjustments')
            ->get()
            ->groupBy('employee_id');
    }

    public function calculationUrl(RecruiterIncentiveCalculation $calculation): string
    {
        return RecruiterIncentiveCalculationResource::getUrl('view', ['record' => $calculation]);
    }
}
