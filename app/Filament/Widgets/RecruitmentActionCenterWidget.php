<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesWidget;
use App\Filament\Widgets\Concerns\LoadsAfterFirstPaint;
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
    use AuthorizesWidget, InteractsWithPageFilters, LoadsAfterFirstPaint, ResolvesDashboardPeriod;

    protected static function requiredPermission(): string
    {
        return 'candidates.viewAny';
    }

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
