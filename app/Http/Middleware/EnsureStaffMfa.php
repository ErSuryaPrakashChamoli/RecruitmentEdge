<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\MfaService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.4: the panel's "MFA required" middleware, decided per person: anyone whose role or
 * permissions require MFA (MfaService) and has not enrolled is sent to the enrolment page before
 * any panel page. Others pass through.
 */
class EnsureStaffMfa
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $this->mfa->isEnforced() || ! $this->mfa->isRequiredFor($user) || $this->mfa->isEnabledFor($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
