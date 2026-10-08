<?php

use App\Enums\CommunicationChannel;
use App\Enums\MeetingProvider;
use App\Enums\PreferenceStatus;
use App\Filament\Resources\CalendarConnections\CalendarConnectionResource;
use App\Jobs\SyncInterviewCalendarJob;
use App\Models\AuditLog;
use App\Models\CalendarConnection;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Integrations\Calendar\CalendarSyncService;
use App\Services\InterviewSchedulingService;
use App\Services\InterviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    config([
        'services.google_calendar' => ['client_id' => 'gid', 'client_secret' => 'gsecret'],
        'services.microsoft_graph' => ['client_id' => 'mid', 'client_secret' => 'msecret', 'tenant' => 'common'],
        'services.zoom' => ['account_id' => 'za', 'client_id' => 'zid', 'client_secret' => 'zsecret'],
    ]);
});

function calendarInterview(Employee $interviewer, array $attributes = []): Interview
{
    $application = CandidateApplication::factory()->create();
    $application->candidate->update(['email' => 'cand@example.com']);

    return Interview::factory()->create(['candidate_application_id' => $application->id, 'interviewer_id' => $interviewer->id, 'scheduled_at' => now()->addDays(2)->setTime(10, 0), 'meeting_link' => null, ...$attributes]);
}

test('scheduling an interview creates the Google event with a Meet link, stored on the interview', function (): void {
    Http::fake(['www.googleapis.com/calendar/v3/calendars/*' => Http::response(['id' => 'evt-1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'conferenceData' => ['conferenceId' => 'abc-defg-hij']])]);
    $connection = CalendarConnection::factory()->create();
    $interview = calendarInterview($connection->employee, ['meeting_provider' => MeetingProvider::GoogleMeet]);

    app(CalendarSyncService::class)->sync($interview, 'create');

    $interview->refresh();

    expect($interview->meeting_link)->toBe('https://meet.google.com/abc-defg-hij')
        ->and($interview->external_meeting_id)->toBe('abc-defg-hij')
        ->and($interview->calendarEvents()->sole()->external_event_id)->toBe('evt-1')
        ->and(AuditLog::query()->where('action', 'calendar_event_created')->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'conferenceDataVersion=1')
        && $request['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet'
        && $request['attendees'] === [['email' => 'cand@example.com']]
        && $request->hasHeader('Authorization', 'Bearer '.$connection->access_token));
});

test('rescheduling updates the same external event and cancelling cancels it', function (): void {
    Http::fake([
        'www.googleapis.com/*' => Http::sequence()
            ->push(['id' => 'evt-9'])
            ->push(['id' => 'evt-9'])
            ->push(null, 204),
    ]);
    $connection = CalendarConnection::factory()->create();
    $interview = calendarInterview($connection->employee);
    $sync = app(CalendarSyncService::class);

    $sync->sync($interview, 'create');
    $sync->sync($interview, 'update');
    $sync->sync($interview, 'cancel');

    $methods = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method())->all();

    expect($methods)->toBe(['POST', 'PATCH', 'DELETE'])
        ->and($interview->calendarEvents()->count())->toBe(1)
        ->and($interview->calendarEvents()->sole()->status)->toBe('cancelled');
});

test('a candidate who opted out of email is not invited to the calendar event', function (): void {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-2'])]);
    $connection = CalendarConnection::factory()->create();
    $interview = calendarInterview($connection->employee);
    app(CommunicationPreferenceService::class)->set($interview->candidateApplication->candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'recruiter');

    app(CalendarSyncService::class)->sync($interview, 'create');

    Http::assertSent(fn ($request) => ! isset($request['attendees']) || $request['attendees'] === []);
});

test('an expired token is refreshed before the call', function (): void {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
        'www.googleapis.com/*' => Http::response(['id' => 'evt-3']),
    ]);
    $connection = CalendarConnection::factory()->create(['token_expires_at' => now()->subMinute()]);

    app(CalendarSyncService::class)->sync(calendarInterview($connection->employee), 'create');

    expect($connection->fresh()->access_token)->toBe('new-token');
});

test('a failed token refresh marks the connection for reconnection', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $broken = CalendarConnection::factory()->create(['token_expires_at' => now()->subMinute()]);

    expect(fn () => app(CalendarSyncService::class)->sync(calendarInterview($broken->employee), 'create'))->toThrow(DomainException::class, 'reconnect');
    expect($broken->fresh()->status)->toBe('error');
});

test('a temporary calendar failure is retried by the job and never changes the interview', function (): void {
    Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'backend']], 503)]);
    $connection = CalendarConnection::factory()->create();
    $interview = calendarInterview($connection->employee);
    $scheduledAt = $interview->scheduled_at;

    expect(fn () => app()->call([new SyncInterviewCalendarJob($interview->id, 'create'), 'handle']))->toThrow(RuntimeException::class, 'Temporary calendar provider failure');
    expect($interview->fresh()->scheduled_at->equalTo($scheduledAt))->toBeTrue()
        ->and($interview->calendarEvents()->sole()->status)->toBe('failed');
});

test('an interviewer without a connected calendar is simply not synced', function (): void {
    Http::fake();

    expect(app(CalendarSyncService::class)->sync(calendarInterview(Employee::factory()->create()), 'create'))->toBeNull();
    Http::assertNothingSent();
});

test('Microsoft 365 creates a Teams meeting on the event', function (): void {
    Http::fake(['graph.microsoft.com/*' => Http::response(['id' => 'ms-1', 'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/x']])]);
    $connection = CalendarConnection::factory()->create(['provider' => 'microsoft_calendar']);
    $interview = calendarInterview($connection->employee, ['meeting_provider' => MeetingProvider::MicrosoftTeams]);

    app(CalendarSyncService::class)->sync($interview, 'create');

    expect($interview->fresh()->meeting_link)->toBe('https://teams.microsoft.com/l/meetup-join/x');
    Http::assertSent(fn ($request) => $request['isOnlineMeeting'] === true && $request['onlineMeetingProvider'] === 'teamsForBusiness');
});

test('a Zoom meeting is created for Zoom interviews without calendar credentials on the interview', function (): void {
    Http::fake([
        'zoom.us/oauth/token' => Http::response(['access_token' => 'zoom-token']),
        'api.zoom.us/v2/users/me/meetings' => Http::response(['id' => 987654, 'join_url' => 'https://zoom.us/j/987654']),
    ]);
    $interview = calendarInterview(Employee::factory()->create(), ['meeting_provider' => MeetingProvider::Zoom]);

    app(CalendarSyncService::class)->sync($interview, 'create');

    expect($interview->fresh()->meeting_link)->toBe('https://zoom.us/j/987654')
        ->and($interview->fresh()->external_meeting_id)->toBe('987654');
});

test('publishing slots over the interviewer\'s busy calendar time is refused', function (): void {
    Carbon::setTestNow('2026-09-25 09:00:00');
    Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => '2026-10-01T10:15:00Z', 'end' => '2026-10-01T11:00:00Z']]]]])]);
    $connection = CalendarConnection::factory()->create();

    app(InterviewSchedulingService::class)->createSlots($connection->employee, ['starts_at' => '2026-10-01 10:00', 'timezone' => 'UTC', 'duration_minutes' => 30, 'mode' => 'video_call']);
})->throws(DomainException::class, 'calendar is busy');

test('scheduling an interview queues the calendar sync after commit', function (): void {
    Queue::fake();
    $application = CandidateApplication::factory()->create();

    $interview = app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDay(), 'mode' => 'video_call']);

    Queue::assertPushed(SyncInterviewCalendarJob::class, fn ($job) => $job->interviewId === $interview->id && $job->action === 'create' && $job->queue === 'integrations');
});

describe('OAuth connect flow', function (): void {
    beforeEach(function (): void {
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->user->assignRole('recruiter');
        actingAs($this->user);
    });

    test('connecting redirects to Google with a state stored in the session', function (): void {
        $response = $this->get(route('integrations.calendar.connect', 'google_calendar'));

        $state = session('calendar_oauth_state.google_calendar')['state'] ?? null;
        expect($state)->not->toBeNull();
        $response->assertRedirectContains('accounts.google.com')->assertRedirectContains('state='.$state);
    });

    test('a callback with a forged state is refused', function (): void {
        $this->withSession(['calendar_oauth_state.google_calendar' => ['state' => 'expected', 'tenant_id' => $this->tenant->id]])
            ->get(route('integrations.calendar.callback', 'google_calendar').'?state=forged&code=abc')
            ->assertForbidden();
    });

    test('a valid callback stores the tokens encrypted and audits the connection', function (): void {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'plain-access', 'refresh_token' => 'plain-refresh', 'expires_in' => 3600]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'rita@example.com']),
        ]);

        $this->withSession(['calendar_oauth_state.google_calendar' => ['state' => 'expected', 'tenant_id' => $this->tenant->id]])
            ->get(route('integrations.calendar.callback', 'google_calendar').'?state=expected&code=abc')
            ->assertRedirect();

        $connection = CalendarConnection::query()->sole();
        $raw = DB::table('calendar_connections')->where('id', $connection->id)->first();

        expect($connection->account_email)->toBe('rita@example.com')
            ->and($connection->access_token)->toBe('plain-access')
            ->and($raw->access_token)->not->toContain('plain-access')
            ->and($raw->refresh_token)->not->toContain('plain-refresh')
            ->and(json_encode(AuditLog::query()->where('action', 'calendar_connected')->sole()->getAttribute('changes')))->not->toContain('plain-');
    });

    test('an unconfigured provider cannot be connected', function (): void {
        config(['services.microsoft_graph' => ['client_id' => null, 'client_secret' => null]]);

        $this->get(route('integrations.calendar.connect', 'microsoft_calendar'))->assertRedirect(CalendarConnectionResource::getUrl());

        expect(collect(session('filament.notifications'))->pluck('title')->all())->toContain('This calendar provider is not configured. Ask an administrator to add its OAuth credentials.');
    });
});
