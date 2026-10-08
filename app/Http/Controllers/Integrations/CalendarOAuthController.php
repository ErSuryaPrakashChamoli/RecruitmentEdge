<?php

namespace App\Http\Controllers\Integrations;

use App\Filament\Resources\CalendarConnections\CalendarConnectionResource;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integrations\Calendar\CalendarConnectionService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * OAuth connect flow for an employee's own calendar (Phase 5). The `state` value is kept in the
 * session and must round-trip unchanged (CSRF protection for the callback); tokens never appear
 * in a URL, response or log.
 */
class CalendarOAuthController extends Controller
{
    public function redirect(Request $request, string $provider, CalendarConnectionService $connections): RedirectResponse
    {
        $tenant = TenantContext::current()->requireTenant();
        abort_unless($request->user() instanceof User && $request->user()->canAccessTenant($tenant), 404);
        abort_unless((bool) $request->user()?->can('calendar.connect') && $request->user()?->employee !== null, 403);

        try {
            $auth = $connections->authorizationRequest($provider, route('integrations.calendar.callback', $provider));
        } catch (DomainException $e) {
            return $this->back($e->getMessage(), 'danger');
        }

        $request->session()->put("calendar_oauth_state.{$provider}", ['state' => $auth['state'], 'tenant_id' => $tenant->getKey()]);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, string $provider, CalendarConnectionService $connections): RedirectResponse
    {
        $pending = $request->session()->pull("calendar_oauth_state.{$provider}");
        $expected = is_array($pending) ? ($pending['state'] ?? null) : null;
        abort_unless(is_string($expected) && hash_equals($expected, (string) $request->query('state')), 403, 'Invalid OAuth state.');

        // SaaS-1: complete in the tenant the connection was started from, if the person may still
        // act there.
        $tenant = Tenant::query()->find($pending['tenant_id'] ?? null);
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User && $user->canAccessTenant($tenant), 403);

        return TenantContext::current()->run($tenant, fn (): RedirectResponse => $this->complete($request, $user, $provider, $connections));
    }

    private function complete(Request $request, User $user, string $provider, CalendarConnectionService $connections): RedirectResponse
    {
        abort_unless($user->can('calendar.connect') && $user->employee !== null, 403);

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->back('The calendar connection was not authorised.', 'danger');
        }

        try {
            $connections->complete($user->employee, $provider, (string) $request->query('code'), route('integrations.calendar.callback', $provider));
        } catch (Throwable $e) {
            report($e);

            return $this->back('The calendar could not be connected. Please try again.', 'danger');
        }

        return $this->back('Calendar connected.', 'success');
    }

    /**
     * Back to Calendar Connections with a panel notification (Filament shows notifications sent
     * before a redirect on the next page).
     */
    private function back(string $message, string $color): RedirectResponse
    {
        Notification::make()->title($message)->color($color)->send();

        return redirect(CalendarConnectionResource::getUrl());
    }
}
