<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\RecruitmentRequisition;
use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Per-position fulfilment and a configurable risk flag (Sections 15/20), from
 * RecruitmentAnalyticsService::positionHealth() — not period-filtered, since fulfilment/risk are
 * "as of right now" for every open/on-hold requisition, not a date-range aggregate.
 */
class PositionHealthWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.position-health';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, array{requisition: RecruitmentRequisition, required: int, filled: int, remaining: int, fulfilment_percent: float, pipeline: int, ageing_days: int, is_overdue: bool, risk: string, url: string}>
     */
    public function getRows(): Collection
    {
        return app(RecruitmentAnalyticsService::class)->positionHealth($this->filteredUser())
            ->map(fn (array $row) => [
                ...$row,
                'url' => RecruitmentRequisitionResource::getUrl('edit', ['record' => $row['requisition']]),
            ])
            ->sortBy(fn (array $row) => match ($row['risk']) {
                'critical' => 0,
                'at_risk' => 1,
                default => 2,
            })
            ->values();
    }

    public function getSummary(): array
    {
        $rows = $this->getRows();

        return [
            'total' => $rows->count(),
            'on_track' => $rows->where('risk', 'on_track')->count(),
            'at_risk' => $rows->where('risk', 'at_risk')->count(),
            'critical' => $rows->where('risk', 'critical')->count(),
        ];
    }
}
