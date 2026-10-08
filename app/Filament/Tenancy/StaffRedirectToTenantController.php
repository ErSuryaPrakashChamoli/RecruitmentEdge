<?php

namespace App\Filament\Tenancy;

use App\Filament\Pages\Auth\ChooseTenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Controllers\RedirectToTenantController;
use Illuminate\Http\RedirectResponse;

/**
 * SaaS-2: /admin with no tenant in the URL. The person's default tenant when there is one
 * (User::getDefaultTenant — a convenience; the tenant page itself checks the membership again),
 * otherwise the organisation chooser. Never a tenant picked for them.
 */
class StaffRedirectToTenantController extends RedirectToTenantController
{
    public function __invoke(): RedirectResponse
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $user = Filament::auth()->user();
        $tenant = $user !== null ? Filament::getUserDefaultTenant($user) : null;

        if ($tenant !== null) {
            return redirect($panel->getUrl($tenant));
        }

        abort_unless($user instanceof User && $user->accessibleTenants()->isNotEmpty(), 404);

        return redirect(ChooseTenant::getUrl());
    }
}
