<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\RecruitmentActionCenterService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * "Recruitment Insights" (Section 12): the same real, DB-driven alert messages
 * RecruitmentActionCenterService::alerts() already computes (e.g. "N vacancies have exceeded
 * SLA"), presented as its own executive-intelligence panel rather than duplicated inside the
 * Action Center's actionable work queue. Deliberately separate from the AI-narrated
 * SmartRecommendationsWidget, which stays AI-gated — this widget never depends on an AI provider
 * being configured, since every message here is a plain computed fact, not a model narration.
 */
class RecruitmentInsightsWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recruitment-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, array{key: string, label: string, severity: string, message: string}>
     */
    public function getInsights(): Collection
    {
        return app(RecruitmentActionCenterService::class)->alerts($this->filteredUser());
    }
}
