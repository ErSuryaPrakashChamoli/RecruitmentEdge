<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\RecruitmentActionCenterService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Today's Action Center (Section 10) and the Pendency/Work Queue (Section 11) merged into one
 * prioritized, clickable queue — both are the same underlying concept (real pending records from
 * RecruitmentActionCenterService) at different granularity, so a second widget would just repeat
 * the same rows. Alerts (Section 23) render as their own "Recruitment Insights" widget/panel
 * (RecruitmentInsightsWidget, Section 12) rather than duplicated here.
 */
class RecruitmentActionCenterWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recruitment-action-center';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, array{key: string, label: string, priority: string, count: int, url: string|null}>
     */
    public function getPendingWork(): Collection
    {
        return app(RecruitmentActionCenterService::class)->pendingWork($this->filteredUser());
    }
}
