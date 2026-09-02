<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Leaderboard;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\PerformanceEngine;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Compact recruiter performance + score summary (Sections 7/8), built entirely on the existing
 * PerformanceEngine composite score (admin-configurable weights via RecruiterPerformanceRule) —
 * the full breakdown/ranking UI already lives on the Leaderboard page, linked from here.
 */
class RecruiterLeaderboardWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recruiter-leaderboard';

    protected int|string|array $columnSpan = 1;

    public function getLeaderboardUrl(): string
    {
        return Leaderboard::getUrl();
    }

    /**
     * True when the acting user has no one below them (a plain recruiter viewing their own
     * numbers), so the widget can say "My Performance" instead of "Team Performance".
     */
    public function isIndividualView(): bool
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return $visibleIds !== null && $visibleIds->count() <= 1;
    }

    /**
     * @return Collection<int, array{recruiter: Employee, score: float|null}>
     */
    public function getRows(): Collection
    {
        [$start, $end] = $this->resolvePeriod();

        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $recruiterIds = CandidateApplication::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('recruiter_id', $visibleIds))
            ->distinct()
            ->pluck('recruiter_id');

        $recruiters = Employee::query()->whereIn('id', $recruiterIds)->get();
        $engine = app(PerformanceEngine::class);

        return $recruiters
            ->map(fn (Employee $recruiter) => [
                'recruiter' => $recruiter,
                'score' => $engine->computeFor($recruiter, $start, $end)['score'],
            ])
            ->sortByDesc('score')
            ->values()
            ->take(8);
    }
}
