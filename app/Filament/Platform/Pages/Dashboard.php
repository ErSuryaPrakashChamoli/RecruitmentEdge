<?php

namespace App\Filament\Platform\Pages;

use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Filament\Platform\Widgets\CommercialOverview;
use App\Filament\Platform\Widgets\PlatformOverview;
use App\Filament\Platform\Widgets\RecentPlatformAudit;
use App\Filament\Platform\Widgets\RecentPlatformEvents;
use App\Services\Platform\PlatformAuthorization;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;

/**
 * SaaS-5: the platform's home — what needs an operator now (PlatformOverview), and the commercial
 * picture with the latest events and platform activity. Its widgets are listed explicitly: other
 * platform widgets (a tenant's members) belong on their own pages.
 */
class Dashboard extends BaseDashboard
{
    use InteractsWithPlatform;

    protected static ?string $title = 'Platform overview';

    public static function canAccess(): bool
    {
        return app(PlatformAuthorization::class)->isOperator(self::signedIn());
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [PlatformOverview::class, CommercialOverview::class, RecentPlatformEvents::class, RecentPlatformAudit::class];
    }
}
