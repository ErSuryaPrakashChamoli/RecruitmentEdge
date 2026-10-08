<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Metrics\MetricPeriod;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

/**
 * Every Command Center widget reads the same shared filters form on Dashboard (via
 * InteractsWithPageFilters -> $this->pageFilters) rather than defining its own bespoke filter
 * fields, so the "period" and "recruiter" selectors update every widget consistently.
 */
trait ResolvesDashboardPeriod
{
    /**
     * The selected period as business-timezone calendar days (Phase 8.5 D16/D17). Returned as the
     * first and last instant of the period in the business timezone, so every service reads the same
     * calendar days whatever timezone it formats in.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function resolvePeriod(): array
    {
        $period = $this->resolveMetricPeriod();

        return [$period->from, $period->to->endOfDay()];
    }

    protected function resolveMetricPeriod(): MetricPeriod
    {
        $filters = $this->pageFilters ?? [];

        return MetricPeriod::preset($filters['period'] ?? 'this_month', $filters['start'] ?? null, $filters['end'] ?? null);
    }

    /**
     * The user whose hierarchy scope every widget query should use. With no recruiter selected
     * (or a selected recruiter the viewer is not allowed to see — a tampered filter value), this is
     * the logged-in viewer. With a visible recruiter selected, it is a transient, never-persisted
     * User carrying only that recruiter's employee_id: HierarchyService then scopes to exactly that
     * recruiter and anyone below them, regardless of the recruiter's own login/permissions (they
     * may have no User account at all). Never use this for auditing or AI usage attribution — use
     * Filament::auth()->user() for "who is acting".
     */
    protected function filteredUser(): User
    {
        /** @var User $viewer */
        $viewer = Filament::auth()->user();
        $recruiter = $this->filteredRecruiter();

        if ($recruiter === null || ! app(HierarchyService::class)->canView($viewer, $recruiter)) {
            return $viewer;
        }

        return User::standInFor($recruiter);
    }

    protected function filteredRecruiterId(): ?int
    {
        $filters = $this->pageFilters ?? [];

        return filled($filters['recruiter_id'] ?? null) ? (int) $filters['recruiter_id'] : null;
    }

    protected function filteredRecruiter(): ?Employee
    {
        $id = $this->filteredRecruiterId();

        return $id !== null ? Employee::query()->find($id) : null;
    }
}
