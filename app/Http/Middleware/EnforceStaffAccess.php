<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\SessionRevocationService;
use App\Services\Identity\StaffAccessService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.4: runs on every panel request, including Livewire updates (persistent auth middleware).
 * A login that is no longer permitted (suspended, revoked, separated), or a session from before the
 * user's last "sign out everywhere" / revocation, is signed out on the spot; Filament's
 * Authenticate middleware then sends the request to the login page.
 */
class EnforceStaffAccess
{
    public function __construct(
        private readonly StaffAccessService $access,
        private readonly SessionRevocationService $sessions,
        private readonly EmployeeLifecycleService $lifecycle,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $request->hasSession() && (! $this->access->permits($user) || ! $this->sessions->isCurrent($request->session(), $user))) {
            Log::info('identity.session_rejected', ['user_id' => $user->getKey(), 'permitted' => $this->access->permits($user)]);

            // A separation that took effect but was not applied yet is applied now (idempotent),
            // so employment and access catch up the moment the person tries to use the system.
            $this->lifecycle->applyDueSeparationFor($user);

            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
