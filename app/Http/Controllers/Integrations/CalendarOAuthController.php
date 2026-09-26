<?php

namespace App\Http\Controllers\Integrations;

use App\Filament\Resources\CalendarConnections\CalendarConnectionResource;
use App\Http\Controllers\Controller;
use App\Services\Integrations\Calendar\CalendarConnectionService;
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
        abort_unless((bool) $request->user()?->can('calendar.connect') && $request->user()?->employee !== null, 403);

        try {
            $auth = $connections->authorizationRequest($provider, route('integrations.calendar.callback', $provider));
        } catch (DomainException $e) {
            return $this->back($e->getMessage(), 'danger');
        }

        $request->session()->put("calendar_oauth_state.{$provider}", $auth['state']);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, string $provider, CalendarConnectionService $connections): RedirectResponse
    {
        $user = $request->user();
        abort_unless((bool) $user?->can('calendar.connect') && $user?->employee !== null, 403);

        $expected = $request->session()->pull("calendar_oauth_state.{$provider}");
        abort_unless(is_string($expected) && hash_equals($expected, (string) $request->query('state')), 403, 'Invalid OAuth state.');

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
