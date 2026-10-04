<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\SessionRevocationService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.4: runs on every panel request, including Livewire updates (persistent auth middleware).
 * A session from before the user's last "sign out everywhere" / revocation is signed out on the
 * spot; Filament's Authenticate middleware then sends the request to the login page.
 *
 * SaaS-2: the identity is signed out when it may no longer use the panel at all — the platform
 * disabled it, or no tenant is left to it (every membership suspended or revoked, every tenant
 * unusable, employment ended everywhere). A person who still belongs to another tenant stays signed
 * in; the tenant they lost is refused by its own membership check (canAccessTenant) on every
 * request, so nothing depends on the session to close it.
 */
class EnforceStaffAccess
{
    public function __construct(
        private readonly SessionRevocationService $sessions,
        private readonly EmployeeLifecycleService $lifecycle,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $request->hasSession() && (! $this->sessions->isCurrent($request->session(), $user) || ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))) {
            Log::info('identity.session_rejected', ['user_id' => $user->getKey(), 'session_current' => $this->sessions->isCurrent($request->session(), $user)]);

            $this->applyDueSeparations($user);

            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }

    /**
     * A separation that took effect but was not applied yet is applied now (idempotent), in each
     * usable tenant that employs the person, so employment and access catch up the moment the
     * person tries to use the system.
     */
    private function applyDueSeparations(User $user): void
    {
        $tenantIds = $user->memberships()
            ->whereNotNull('employee_id')
            ->whereHas('tenant', fn ($tenant) => $tenant->whereIn('status', TenantStatus::usableValues()))
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            TenantContext::current()->run((int) $tenantId, fn (): bool => $this->lifecycle->applyDueSeparationFor($user));
        }
    }
}
