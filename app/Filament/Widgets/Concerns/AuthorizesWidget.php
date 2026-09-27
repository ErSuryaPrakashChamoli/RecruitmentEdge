<?php

namespace App\Filament\Widgets\Concerns;

use Filament\Facades\Filament;

/**
 * Phase 8.5 (SEC-1): a widget renders only for a viewer holding the permission its data needs —
 * never for every panel user just because the page is reachable. The data itself is still
 * hierarchy-scoped by the services; this gate stops team metrics reaching a user who may not see
 * them at all.
 */
trait AuthorizesWidget
{
    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->can(static::requiredPermission());
    }

    protected static function requiredPermission(): string
    {
        return 'performance.view';
    }
}
