<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\CandidateJoining;
use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Offer pipeline (Section 18) and joining pipeline (Section 19), merged into one widget since
 * offer-accepted flows directly into joining in this system (OfferService dispatches
 * OfferAccepted -> CandidateJoining is created automatically). Both halves are read straight from
 * RecruitmentAnalyticsService; joining risk reuses CandidateJoining::riskLevel() via
 * joiningRisks(), never reimplemented.
 */
class OfferJoiningAnalyticsWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.offer-joining-analytics';

    protected int|string|array $columnSpan = 1;

    public function getOffers(): array
    {
        [$start, $end] = $this->resolvePeriod();

        return app(RecruitmentAnalyticsService::class)->offerAnalytics($start, $end, $this->filteredUser());
    }

    public function getJoining(): array
    {
        [$start, $end] = $this->resolvePeriod();

        return app(RecruitmentAnalyticsService::class)->joiningAnalytics($start, $end, $this->filteredUser());
    }

    /**
     * @return Collection<int, array{joining: CandidateJoining, risk: string}>
     */
    public function getRisks(): Collection
    {
        return app(RecruitmentAnalyticsService::class)->joiningRisks($this->filteredUser())->take(5);
    }
}
