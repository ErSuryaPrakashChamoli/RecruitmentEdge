<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesWidget;
use App\Filament\Widgets\Concerns\LoadsAfterFirstPaint;
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
    use AuthorizesWidget, InteractsWithPageFilters, LoadsAfterFirstPaint, ResolvesDashboardPeriod;

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
