<?php

namespace App\Services\Integrations\Calendar\Providers;

use App\Models\CalendarConnection;
use App\Services\Communication\Data\IntegrationTestResult;
use App\Services\Integrations\Calendar\CalendarProvider;
use App\Services\Integrations\Calendar\Data\CalendarEventData;
use App\Services\Integrations\Calendar\Data\CalendarEventResult;
use App\Services\Integrations\Calendar\Data\OAuthTokens;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Calendar API v3 adapter (OAuth 2.0 authorization-code flow with offline refresh).
 * Google Meet links are created by requesting conferenceData on the event. Client credentials:
 * config('services.google_calendar'). Tokens are passed in per call from the encrypted
 * CalendarConnection and never logged.
 */
class GoogleCalendarProvider implements CalendarProvider
{
    private const string API = 'https://www.googleapis.com/calendar/v3';

    public function key(): string
    {
        return 'google_calendar';
    }

    public function label(): string
    {
        return 'Google Calendar (with Google Meet)';
    }

    public function category(): string
    {
        return 'calendar';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google_calendar.client_id')) && filled(config('services.google_calendar.client_secret'));
    }

    public function testConnection(): IntegrationTestResult
    {
        if (! $this->isConfigured()) {
            return new IntegrationTestResult(false, 'Google OAuth client credentials are not configured.');
        }

        $connection = CalendarConnection::query()->where('provider', $this->key())->where('status', 'active')->latest('connected_at')->first();

        if ($connection === null) {
            return new IntegrationTestResult(false, 'Credentials are set, but no employee has connected a Google calendar yet — connect one from Calendar Connections, then test again.');
        }

        try {
            $response = Http::withToken($this->token($connection))->timeout(10)->get(self::API.'/users/me/calendarList', ['maxResults' => 1]);
        } catch (ConnectionException $e) {
            return new IntegrationTestResult(false, 'Google Calendar unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? new IntegrationTestResult(true, 'Read the calendar list of '.($connection->account_email ?? 'a connected account').'.')
            : new IntegrationTestResult(false, 'Google Calendar rejected the request ('.$response->status().').');
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy https://www.googleapis.com/auth/calendar.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens
    {
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $this->ensureOk($response, 'token exchange');

        $email = Http::withToken((string) $response->json('access_token'))->timeout(10)->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        return new OAuthTokens((string) $response->json('access_token'), $response->json('refresh_token'), now()->addSeconds((int) $response->json('expires_in', 3600)), $email);
    }

    public function refresh(CalendarConnection $connection): OAuthTokens
    {
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $this->ensureOk($response, 'token refresh');

        return new OAuthTokens((string) $response->json('access_token'), $response->json('refresh_token') ?? $connection->refresh_token, now()->addSeconds((int) $response->json('expires_in', 3600)), $connection->account_email);
    }

    public function createEvent(CalendarConnection $connection, CalendarEventData $event): CalendarEventResult
    {
        return $this->eventCall(fn () => Http::withToken($this->token($connection))->timeout(15)
            ->post($this->eventsUrl($connection).'?'.http_build_query(['conferenceDataVersion' => 1, 'sendUpdates' => 'all']), $this->payload($event)));
    }

    public function updateEvent(CalendarConnection $connection, string $externalEventId, CalendarEventData $event): CalendarEventResult
    {
        return $this->eventCall(fn () => Http::withToken($this->token($connection))->timeout(15)
            ->patch($this->eventsUrl($connection).'/'.rawurlencode($externalEventId).'?'.http_build_query(['conferenceDataVersion' => 1, 'sendUpdates' => 'all']), $this->payload($event)));
    }

    public function cancelEvent(CalendarConnection $connection, string $externalEventId): CalendarEventResult
    {
        try {
            $response = Http::withToken($this->token($connection))->timeout(15)
                ->delete($this->eventsUrl($connection).'/'.rawurlencode($externalEventId).'?sendUpdates=all');
        } catch (ConnectionException $e) {
            return CalendarEventResult::failed('Google Calendar unreachable: '.$e->getMessage(), retryable: true);
        }

        return $response->successful() || $response->status() === 410
            ? new CalendarEventResult(true, $externalEventId)
            : CalendarEventResult::failed('Google Calendar error '.$response->status(), $response->serverError() || $response->status() === 429);
    }

    public function getEvent(CalendarConnection $connection, string $externalEventId): CalendarEventResult
    {
        return $this->eventCall(fn () => Http::withToken($this->token($connection))->timeout(15)->get($this->eventsUrl($connection).'/'.rawurlencode($externalEventId)));
    }

    public function busyTimes(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end): array
    {
        $response = Http::withToken($this->token($connection))->timeout(15)->post(self::API.'/freeBusy', [
            'timeMin' => $start->copy()->utc()->toIso8601ZuluString(),
            'timeMax' => $end->copy()->utc()->toIso8601ZuluString(),
            'items' => [['id' => $connection->calendar_id]],
        ]);

        $this->ensureOk($response, 'free/busy');

        return collect($response->json("calendars.{$connection->calendar_id}.busy", []))
            ->map(fn (array $slot) => ['start' => Carbon::parse($slot['start']), 'end' => Carbon::parse($slot['end'])])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CalendarEventData $event): array
    {
        return array_filter([
            'summary' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'start' => ['dateTime' => $event->start->copy()->utc()->toIso8601ZuluString(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $event->end->copy()->utc()->toIso8601ZuluString(), 'timeZone' => 'UTC'],
            'attendees' => array_map(fn (string $email) => ['email' => $email], $event->attendees),
            'conferenceData' => $event->requestConference ? ['createRequest' => ['requestId' => $event->reference !== '' ? $event->reference : (string) Str::uuid(), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]] : null,
        ], fn ($value) => $value !== null);
    }

    private function eventCall(callable $call): CalendarEventResult
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException $e) {
            return CalendarEventResult::failed('Google Calendar unreachable: '.$e->getMessage(), retryable: true);
        }

        if (! $response->successful()) {
            return CalendarEventResult::failed('Google Calendar error '.$response->status().': '.($response->json('error.message') ?? 'unknown'), $response->serverError() || $response->status() === 429);
        }

        return new CalendarEventResult(
            true,
            $response->json('id'),
            $response->json('hangoutLink') ?? collect($response->json('conferenceData.entryPoints', []))->firstWhere('entryPointType', 'video')['uri'] ?? null,
            $response->json('conferenceData.conferenceId'),
        );
    }

    private function eventsUrl(CalendarConnection $connection): string
    {
        return self::API.'/calendars/'.rawurlencode($connection->calendar_id).'/events';
    }

    private function token(CalendarConnection $connection): string
    {
        return (string) $connection->access_token;
    }

    private function ensureOk(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw new DomainException("Google {$operation} failed ({$response->status()}).");
        }
    }
}
