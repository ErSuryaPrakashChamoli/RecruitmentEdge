<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\User;
use App\Services\AI\Gateway\AiGateway;
use App\Services\RecruitmentInsightsService;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * "Smart Recommendations" (Section 24): user-triggered (never eager/polling, to control AI cost
 * and avoid the rate-limited `advanced` model — see config/ai.php). Always visible: the database
 * facts (funnel, turn-up, positions at risk, accountability, alerts, pending work) render for
 * everyone, scoped to the dashboard's period and recruiter filters. The AI narration section only
 * appears when an AI provider is configured AND the viewer holds `ai.query` — the dashboard never
 * depends on AI being present.
 */
class SmartRecommendationsWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.smart-recommendations';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array{facts: array<string, mixed>, narrative: string|null, configured: bool}|null
     */
    public ?array $result = null;

    public function canNarrate(): bool
    {
        return (bool) Filament::auth()->user()?->can('ai.query') && app(AiGateway::class)->isConfigured();
    }

    public function generate(): void
    {
        [$start, $end] = $this->resolvePeriod();

        /** @var User $viewer */
        $viewer = Filament::auth()->user();
        $scopeUser = $this->filteredUser();

        $this->result = app(RecruitmentInsightsService::class)->generate(
            viewer: $scopeUser->employee,
            user: $viewer,
            start: $start,
            end: $end,
            scope: $scopeUser,
            narrate: (bool) $viewer->can('ai.query'),
        );
    }
}
