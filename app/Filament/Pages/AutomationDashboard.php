<?php

namespace App\Filament\Pages;

use App\Enums\Entitlement;
use App\Filament\Widgets\Automation\AutomationStats;
use App\Models\User;
use App\Services\Automation\AutomationHealthService;
use App\Services\Entitlements\EntitlementService;
use App\Services\RecruitmentAnalyticsService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Automation Dashboard (Phase 6): health (Healthy / Warning / Failed with the signals behind it),
 * 30-day activity and per-rule failure rates. Kept off the main dashboard on purpose.
 */
class AutomationDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Automation Dashboard';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Automation Dashboard';

    protected string $view = 'filament.pages.automation-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // SaaS-3: and automation in the tenant's plan.
        return $user !== null && ($user->can('automation.analytics') || $user->can('automation.view')) && app(EntitlementService::class)->allows(Entitlement::AutomationRules);
    }

    protected function getHeaderWidgets(): array
    {
        return [AutomationStats::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealth(): array
    {
        return app(AutomationHealthService::class)->overview();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return app(RecruitmentAnalyticsService::class)->automationAnalytics(now()->subDays(30), now(), $user);
    }
}
