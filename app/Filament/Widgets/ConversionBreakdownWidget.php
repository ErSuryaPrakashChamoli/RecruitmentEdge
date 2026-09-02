<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Turn-up -> Selection -> Joining ratios sliced by recruiter, position, or source (Sections 5/6),
 * built entirely on RecruitmentAnalyticsService::conversionBreakdown() — the toggle just changes
 * which grouping is requested.
 */
class ConversionBreakdownWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.conversion-breakdown';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var 'recruiter'|'requisition'|'source'
     */
    public string $groupBy = 'recruiter';

    public function setGroupBy(string $groupBy): void
    {
        if (in_array($groupBy, ['recruiter', 'requisition', 'source'], true)) {
            $this->groupBy = $groupBy;
        }
    }

    /**
     * @return Collection<int, array{group: string, turnups: int, selections: int, joined: int, selection_ratio: float|null, joining_ratio: float|null}>
     */
    public function getRows(): Collection
    {
        [$start, $end] = $this->resolvePeriod();

        return app(RecruitmentAnalyticsService::class)
            ->conversionBreakdown($this->groupBy, $start, $end, $this->filteredUser())
            ->sortByDesc('turnups')
            ->values();
    }
}
