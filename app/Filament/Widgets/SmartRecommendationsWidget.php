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
 * and avoid the rate-limited `advanced` model — see config/ai.php) narration over the same facts
 * the rest of the Command Center already computed, via the existing provider-agnostic AiGateway.
 * Hidden entirely when AI isn't configured or the viewer lacks ai.query, so the dashboard never
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

    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->can('ai.query') && app(AiGateway::class)->isConfigured();
    }

    public function generate(): void
    {
        [$start, $end] = $this->resolvePeriod();

        /** @var User $user */
        $user = Filament::auth()->user();

        $this->result = app(RecruitmentInsightsService::class)->generate($user->employee, $user, $start, $end);
    }
}
