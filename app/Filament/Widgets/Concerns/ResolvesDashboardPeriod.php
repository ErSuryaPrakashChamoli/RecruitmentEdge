<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Employee;
use App\Models\User;
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
     * The acting user, honoring the "recruiter" filter when the viewer has selected one specific
     * recruiter to inspect (still bounded by that viewer's own hierarchy — see the widgets that
     * call this: they resolve visibility from this user, never from the raw filter value).
     */
    protected function filteredUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
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
