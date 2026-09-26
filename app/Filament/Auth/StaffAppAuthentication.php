<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\Identity\MfaService;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;

/**
 * Phase 8.4: Filament's authenticator-app MFA, except that someone whose role requires MFA is not
 * offered "turn off" (the User model refuses it as well).
 */
class StaffAppAuthentication extends AppAuthentication
{
    public function getActions(): array
    {
        $user = Filament::auth()->user();
        $mfa = app(MfaService::class);

        return collect(parent::getActions())
            ->map(fn (Action $action) => $action->getName() === 'disableAppAuthentication' && $user instanceof User && $mfa->isEnforced() && $mfa->isRequiredFor($user)
                ? $action->visible(false)
                : $action)
            ->all();
    }
}
