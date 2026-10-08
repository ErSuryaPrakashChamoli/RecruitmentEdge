<?php

namespace App\Filament\Platform\Pages;

use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Services\Platform\PlatformAuthorization;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * SaaS-5: the platform's home — what needs an operator now (PlatformOverview).
 */
class Dashboard extends BaseDashboard
{
    use InteractsWithPlatform;

    protected static ?string $title = 'Platform overview';

    public static function canAccess(): bool
    {
        return app(PlatformAuthorization::class)->isOperator(self::signedIn());
    }
}
