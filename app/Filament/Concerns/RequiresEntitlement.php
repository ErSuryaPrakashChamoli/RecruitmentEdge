<?php

namespace App\Filament\Concerns;

use App\Enums\Entitlement;
use App\Services\Entitlements\EntitlementService;

/**
 * SaaS-3: a resource whose module the tenant's plan must include. Filament asks canAccess() for the
 * navigation, on mount and on every Livewire update, so a module outside the plan is hidden and
 * its URLs and Livewire calls are refused (403) — in addition to the person's own permissions,
 * never instead of them. The services refuse the operations on their own as well.
 */
trait RequiresEntitlement
{
    abstract protected static function requiredEntitlement(): Entitlement;

    public static function canAccess(): bool
    {
        return app(EntitlementService::class)->allows(static::requiredEntitlement()) && parent::canAccess();
    }
}
