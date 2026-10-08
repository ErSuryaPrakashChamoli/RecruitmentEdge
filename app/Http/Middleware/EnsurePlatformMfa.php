<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\MfaService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-5: the platform panel's "MFA required" middleware — every platform operator, whatever their
 * role, enrols an authenticator app before any platform page (unlike the tenant panel, where it is
 * decided per person). Follows the same enforcement switch (identity.mfa.enforce).
 */
class EnsurePlatformMfa
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $this->mfa->isEnforced() || $this->mfa->isEnabledFor($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
