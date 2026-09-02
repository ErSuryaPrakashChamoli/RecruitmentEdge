<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\RecruitmentSlaService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Per-stage TAT vs configurable SLA targets (Section 13), from RecruitmentSlaService — every
 * number here comes from candidate_stage_histories, never invented.
 */
class SlaTatWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.sla-tat';

    protected int|string|array $columnSpan = 1;

    /**
     * @return Collection<int, array{label: string, average_days: float|null, median_days: float|null, target_days: int, sla_percent: float|null, breaches: int, sample_size: int}>
     */
    public function getRows(): Collection
    {
        [$start, $end] = $this->resolvePeriod();

        return app(RecruitmentSlaService::class)->stageTat($start, $end, $this->filteredUser());
    }

    /**
     * @return array{average_days: float|null, target_days: int, sla_percent: float|null, status: string}
     */
    public function getTimeToHire(): array
    {
        [$start, $end] = $this->resolvePeriod();

        return app(RecruitmentSlaService::class)->timeToHireSummary($start, $end, $this->filteredUser());
    }
}
