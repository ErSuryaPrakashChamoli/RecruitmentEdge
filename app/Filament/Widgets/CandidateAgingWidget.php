<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * How long active candidates have sat at each checkpoint (Section 12), from
 * RecruitmentAnalyticsService::candidateAging() — not period-filtered, since aging is inherently
 * "as of right now", not "within a date range".
 */
class CandidateAgingWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.candidate-aging';

    protected int|string|array $columnSpan = 1;

    /**
     * @return Collection<int, array{stage: \App\Enums\CandidateStage, total: int, buckets: array{0_2: int, 3_5: int, 6_10: int, 10_plus: int}}>
     */
    public function getRows(): Collection
    {
        return app(RecruitmentAnalyticsService::class)->candidateAging($this->filteredUser());
    }
}
