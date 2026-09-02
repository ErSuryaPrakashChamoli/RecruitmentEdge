<?php

namespace App\Filament\Widgets;

use App\Enums\CandidateStage;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The Command Center's headline funnel (Applications -> ... -> Joined) with conversion % and
 * drop-off between consecutive stages, each row linking into the already-filtered Candidate
 * Applications list. Built entirely on the existing RecruitmentAnalyticsService::funnel(), which
 * returns every CandidateStage — this widget narrows the display to the stages the brief names.
 */
class RecruitmentFunnelWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recruitment-funnel';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<int, CandidateStage>
     */
    private const array HEADLINE_STAGES = [
        CandidateStage::Sourced,
        CandidateStage::Shortlisted,
        CandidateStage::InterviewScheduled,
        CandidateStage::Interview1,
        CandidateStage::Selected,
        CandidateStage::OfferReleased,
        CandidateStage::OfferAccepted,
        CandidateStage::Joined,
    ];

    /**
     * @return Collection<int, array{stage: CandidateStage, count: int, conversion_from_sourced: float|null, drop_off_count: int|null, drop_off_percent: float|null, url: string|null}>
     */
    public function getRows(): Collection
    {
        [$start, $end] = $this->resolvePeriod();

        $funnel = app(RecruitmentAnalyticsService::class)
            ->funnel($start, $end, $this->filteredUser())
            ->keyBy(fn (array $row) => $row['stage']->value);

        $previousCount = null;

        return collect(self::HEADLINE_STAGES)
            ->map(fn (CandidateStage $stage) => $funnel->get($stage->value))
            ->filter()
            ->map(function (array $row) use (&$previousCount) {
                $dropOffCount = $previousCount !== null ? max(0, $previousCount - $row['count']) : null;
                $dropOffPercent = ($previousCount !== null && $previousCount > 0)
                    ? round($dropOffCount / $previousCount * 100, 1)
                    : null;
                $previousCount = $row['count'];

                return [
                    ...$row,
                    'drop_off_count' => $dropOffCount,
                    'drop_off_percent' => $dropOffPercent,
                    'url' => CandidateApplicationResource::getUrl('index', [
                        'tableFilters' => ['current_stage' => ['value' => $row['stage']->value]],
                    ]),
                ];
            })
            ->values();
    }
}
