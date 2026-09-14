<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
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
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function resolvePeriod(): array
    {
        $filters = $this->pageFilters ?? [];
        $period = $filters['period'] ?? 'this_month';
        $now = CarbonImmutable::now();

        return match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_30_days' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'custom' => [
                filled($filters['start'] ?? null) ? CarbonImmutable::parse($filters['start'])->startOfDay() : $now->startOfMonth(),
                filled($filters['end'] ?? null) ? CarbonImmutable::parse($filters['end'])->endOfDay() : $now->endOfDay(),
            ],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
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

        $scopedUser = new User;
        $scopedUser->forceFill(['employee_id' => $recruiter->id, 'name' => $recruiter->fullName()]);
        $scopedUser->setRelation('employee', $recruiter);

        return $scopedUser;
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
