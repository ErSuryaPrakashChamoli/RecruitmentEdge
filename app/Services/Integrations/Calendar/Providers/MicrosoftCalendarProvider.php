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

/**
 * Microsoft Graph (Outlook / Microsoft 365) calendar adapter — OAuth 2.0 v2 endpoints, delegated
 * Calendars.ReadWrite. Teams meetings are created with isOnlineMeeting on the event. All
 * Microsoft-specific logic stays in this class. Client credentials: config('services.microsoft_graph').
 */
class MicrosoftCalendarProvider implements CalendarProvider
{
    private const string GRAPH = 'https://graph.microsoft.com/v1.0';

    public function key(): string
    {
        return 'microsoft_calendar';
    }

    public function label(): string
    {
        return 'Microsoft 365 Calendar (with Teams)';
    }

    public function category(): string
    {
        return 'calendar';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.microsoft_graph.client_id')) && filled(config('services.microsoft_graph.client_secret'));
    }

    public function testConnection(): IntegrationTestResult
    {
        if (! $this->isConfigured()) {
            return new IntegrationTestResult(false, 'Microsoft Graph client credentials are not configured.');
        }

        $connection = CalendarConnection::query()->where('provider', $this->key())->where('status', 'active')->latest('connected_at')->first();

        if ($connection === null) {
            return new IntegrationTestResult(false, 'Credentials are set, but no employee has connected a Microsoft calendar yet.');
        }

        try {
            $response = Http::withToken((string) $connection->access_token)->timeout(10)->get(self::GRAPH.'/me/calendar');
        } catch (ConnectionException $e) {
            return new IntegrationTestResult(false, 'Microsoft Graph unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? new IntegrationTestResult(true, 'Read the calendar of '.($connection->account_email ?? 'a connected account').'.')
            : new IntegrationTestResult(false, 'Microsoft Graph rejected the request ('.$response->status().').');
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->authority().'/authorize?'.http_build_query([
            'client_id' => config('services.microsoft_graph.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens
    {
        $response = Http::asForm()->timeout(15)->post($this->authority().'/token', [
            'client_id' => config('services.microsoft_graph.client_id'),
            'client_secret' => config('services.microsoft_graph.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
        ]);

        $this->ensureOk($response, 'token exchange');

        $me = Http::withToken((string) $response->json('access_token'))->timeout(10)->get(self::GRAPH.'/me')->json();

        return new OAuthTokens((string) $response->json('access_token'), $response->json('refresh_token'), now()->addSeconds((int) $response->json('expires_in', 3600)), $me['mail'] ?? $me['userPrincipalName'] ?? null);
    }

    public function refresh(CalendarConnection $connection): OAuthTokens
    {
        $response = Http::asForm()->timeout(15)->post($this->authority().'/token', [
            'client_id' => config('services.microsoft_graph.client_id'),
            'client_secret' => config('services.microsoft_graph.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
        ]);

        $this->ensureOk($response, 'token refresh');

        return new OAuthTokens((string) $response->json('access_token'), $response->json('refresh_token') ?? $connection->refresh_token, now()->addSeconds((int) $response->json('expires_in', 3600)), $connection->account_email);
    }

    public function createEvent(CalendarConnection $connection, CalendarEventData $event): CalendarEventResult
    {
        return $this->eventCall(fn () => Http::withToken((string) $connection->access_token)->timeout(15)->post(self::GRAPH.'/me/events', $this->payload($event)));
    }

    public function updateEvent(CalendarConnection $connection, string $externalEventId, CalendarEventData $event): CalendarEventResult
    {
        return $this->eventCall(fn () => Http::withToken((string) $connection->access_token)->timeout(15)->patch(self::GRAPH.'/me/events/'.rawurlencode($externalEventId), $this->payload($event)));
    }

    public function cancelEvent(CalendarConnection $connection, string $externalEventId): CalendarEventResult
    {
        try {
            $response = Http::withToken((string) $connection->access_token)->timeout(15)
                ->post(self::GRAPH.'/me/events/'.rawurlencode($externalEventId).'/cancel', ['comment' => 'This interview has been cancelled.']);
        } catch (ConnectionException $e) {
            return CalendarEventResult::failed('Microsoft Graph unreachable: '.$e->getMessage(), retryable: true);
        }

        return $response->successful() || $response->status() === 404
            ? new CalendarEventResult(true, $externalEventId)
            : CalendarEventResult::failed('Microsoft Graph error '.$response->status(), $response->serverError() || $response->status() === 429);
    }

    public function getEvent(CalendarConnection $connection, string $externalEventId): CalendarEventResult
    {
        return $this->eventCall(fn () => Http::withToken((string) $connection->access_token)->timeout(15)->get(self::GRAPH.'/me/events/'.rawurlencode($externalEventId)));
    }

    public function busyTimes(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end): array
    {
        $response = Http::withToken((string) $connection->access_token)->timeout(15)->post(self::GRAPH.'/me/calendar/getSchedule', [
            'schedules' => [$connection->account_email],
            'startTime' => ['dateTime' => $start->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'endTime' => ['dateTime' => $end->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        ]);

        $this->ensureOk($response, 'schedule lookup');

        return collect($response->json('value.0.scheduleItems', []))
            ->filter(fn (array $item) => in_array($item['status'] ?? '', ['busy', 'oof', 'tentative'], true))
            ->map(fn (array $item) => ['start' => Carbon::parse($item['start']['dateTime'], 'UTC'), 'end' => Carbon::parse($item['end']['dateTime'], 'UTC')])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CalendarEventData $event): array
    {
        return array_filter([
            'subject' => $event->title,
            'body' => ['contentType' => 'text', 'content' => $event->description],
            'start' => ['dateTime' => $event->start->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $event->end->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'location' => $event->location !== null ? ['displayName' => $event->location] : null,
            'attendees' => array_map(fn (string $email) => ['emailAddress' => ['address' => $email], 'type' => 'required'], $event->attendees),
            'isOnlineMeeting' => $event->requestConference ?: null,
            'onlineMeetingProvider' => $event->requestConference ? 'teamsForBusiness' : null,
            'transactionId' => $event->reference !== '' ? $event->reference : null,
        ], fn ($value) => $value !== null);
    }

    private function eventCall(callable $call): CalendarEventResult
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException $e) {
            return CalendarEventResult::failed('Microsoft Graph unreachable: '.$e->getMessage(), retryable: true);
        }

        if (! $response->successful()) {
            return CalendarEventResult::failed('Microsoft Graph error '.$response->status().': '.($response->json('error.message') ?? 'unknown'), $response->serverError() || $response->status() === 429);
        }

        return new CalendarEventResult(true, $response->json('id'), $response->json('onlineMeeting.joinUrl'), null);
    }

    private function authority(): string
    {
        return 'https://login.microsoftonline.com/'.config('services.microsoft_graph.tenant', 'common').'/oauth2/v2.0';
    }

    private function ensureOk(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw new DomainException("Microsoft {$operation} failed ({$response->status()}).");
        }
    }
}
