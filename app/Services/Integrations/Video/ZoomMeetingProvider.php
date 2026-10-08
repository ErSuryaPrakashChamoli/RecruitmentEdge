<?php

namespace App\Services\Integrations\Video;

use App\Models\Interview;
use App\Services\Communication\Data\IntegrationTestResult;
use App\Services\Integrations\Calendar\Data\CalendarEventResult;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Zoom meetings via a Server-to-Server OAuth app (account credentials grant). The short-lived
 * access token is cached (encrypted cache is not assumed, so the TTL is kept short) and never
 * logged. Credentials: config('services.zoom').
 */
class ZoomMeetingProvider implements VideoMeetingProvider
{
    public function key(): string
    {
        return 'zoom';
    }

    public function label(): string
    {
        return 'Zoom (Server-to-Server OAuth)';
    }

    public function category(): string
    {
        return 'video';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.zoom.account_id')) && filled(config('services.zoom.client_id')) && filled(config('services.zoom.client_secret'));
    }

    public function testConnection(): IntegrationTestResult
    {
        if (! $this->isConfigured()) {
            return new IntegrationTestResult(false, 'Zoom Server-to-Server OAuth credentials are not configured.');
        }

        try {
            $response = Http::withToken($this->token())->timeout(10)->get('https://api.zoom.us/v2/users/me');
        } catch (ConnectionException $e) {
            return new IntegrationTestResult(false, 'Zoom unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? new IntegrationTestResult(true, 'Connected to Zoom as '.($response->json('email') ?? 'the account user').'.')
            : new IntegrationTestResult(false, 'Zoom rejected the credentials ('.$response->status().').');
    }

    public function createMeeting(Interview $interview, int $durationMinutes): CalendarEventResult
    {
        try {
            $response = Http::withToken($this->token())->timeout(15)->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => 'Interview — '.($interview->candidateApplication?->requisition?->designation?->name ?? 'Recruitment'),
                'type' => 2,
                'start_time' => $interview->scheduled_at->copy()->utc()->toIso8601ZuluString(),
                'duration' => $durationMinutes,
                'timezone' => 'UTC',
                'settings' => ['waiting_room' => true, 'join_before_host' => false],
            ]);
        } catch (ConnectionException $e) {
            return CalendarEventResult::failed('Zoom unreachable: '.$e->getMessage(), retryable: true);
        }

        return $response->successful()
            ? new CalendarEventResult(true, null, $response->json('join_url'), (string) $response->json('id'))
            : CalendarEventResult::failed('Zoom error '.$response->status().': '.($response->json('message') ?? 'unknown'), $response->serverError() || $response->status() === 429);
    }

    public function cancelMeeting(string $meetingId): bool
    {
        try {
            return Http::withToken($this->token())->timeout(15)->delete('https://api.zoom.us/v2/meetings/'.rawurlencode($meetingId))->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Phase 8.9 (P89-SEC-009): the access token is cached encrypted — the cache table is readable by
     * anyone with database access, and the token is a bearer credential for the Zoom account.
     */
    private function token(): string
    {
        return Crypt::decryptString(Cache::remember('zoom:s2s-token:v2', now()->addMinutes(50), fn (): string => Crypt::encryptString($this->requestToken())));
    }

    private function requestToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth((string) config('services.zoom.client_id'), (string) config('services.zoom.client_secret'))
            ->timeout(15)
            ->post('https://zoom.us/oauth/token', ['grant_type' => 'account_credentials', 'account_id' => config('services.zoom.account_id')]);

        if (! $response->successful()) {
            throw new DomainException('Zoom token request failed ('.$response->status().').');
        }

        return (string) $response->json('access_token');
    }
}
