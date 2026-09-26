<?php

namespace App\Services\Integrations\Calendar;

use App\Enums\CommunicationChannel;
use App\Enums\MeetingProvider;
use App\Models\AuditLog;
use App\Models\CalendarConnection;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewCalendarEvent;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Integrations\Calendar\Data\CalendarEventData;
use App\Services\Integrations\Calendar\Data\CalendarEventResult;
use App\Services\Integrations\Video\ZoomMeetingProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mirrors interviews into the interviewer's connected calendar and creates video meetings
 * (Phase 5). The Interview stays authoritative: this only creates/updates/cancels the matching
 * external event (one per interview per provider — retries never duplicate it) and, when a
 * provider returns a meeting, stores its join URL on the interview (never credentials).
 *
 * The candidate is invited as an attendee only when their email preference allows it.
 */
class CalendarSyncService
{
    public const int DEFAULT_DURATION_MINUTES = 60;

    public function __construct(
        private readonly CalendarManager $calendars,
        private readonly CalendarConnectionService $connections,
        private readonly CommunicationPreferenceService $preferences,
        private readonly ZoomMeetingProvider $zoom,
    ) {}

    /**
     * @param  string  $action  create | update | cancel
     * @return CalendarEventResult|null null when there is nothing to sync (no connected calendar)
     */
    public function sync(Interview $interview, string $action): ?CalendarEventResult
    {
        $interview->loadMissing('candidateApplication.candidate', 'candidateApplication.requisition.designation', 'interviewer');

        if ($action !== 'cancel') {
            $this->ensureZoomMeeting($interview);
        }

        $connection = $interview->interviewer !== null ? $this->connections->activeConnectionFor($interview->interviewer) : null;

        if ($connection === null) {
            return null;
        }

        $provider = $this->calendars->find($connection->provider);

        if ($provider === null || ! $provider->isConfigured()) {
            return null;
        }

        $this->connections->ensureFreshToken($connection);

        $mapping = InterviewCalendarEvent::query()->firstOrCreate(
            ['interview_id' => $interview->id, 'provider' => $connection->provider],
            ['calendar_connection_id' => $connection->id, 'status' => 'pending'],
        );

        $result = match (true) {
            $action === 'cancel' && $mapping->external_event_id !== null => $provider->cancelEvent($connection, $mapping->external_event_id),
            $action === 'cancel' => new CalendarEventResult(true),
            $mapping->external_event_id !== null => $provider->updateEvent($connection, $mapping->external_event_id, $this->eventData($interview, $connection)),
            default => $provider->createEvent($connection, $this->eventData($interview, $connection)),
        };

        DB::transaction(function () use ($interview, $mapping, $connection, $result, $action): void {
            $mapping->forceFill([
                'external_event_id' => $result->externalEventId ?? $mapping->external_event_id,
                'status' => ! $result->ok ? 'failed' : ($action === 'cancel' ? 'cancelled' : 'synced'),
                'last_error' => $result->error,
                'synced_at' => $result->ok ? now() : $mapping->synced_at,
            ])->save();

            if ($result->ok && $result->meetingUrl !== null && blank($interview->meeting_link)) {
                $interview->forceFill(['meeting_link' => $result->meetingUrl, 'external_meeting_id' => $result->meetingId])->save();
            }

            $connection->forceFill(['last_synced_at' => now()])->save();

            AuditLog::record($mapping, $result->ok ? "calendar_event_{$action}d" : 'calendar_sync_failed', null, array_filter([
                'provider' => $connection->provider,
                'interview_id' => $interview->id,
                'external_event_id' => $mapping->external_event_id,
                'error' => $result->error,
            ]));
        });

        return $result;
    }

    /**
     * Busy intervals of an employee's connected calendar, or [] when none is connected or the
     * provider can't be reached (availability checks must never block on a provider outage).
     *
     * @return array<int, array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function busyTimesFor(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        $connection = $this->connections->activeConnectionFor($employee);
        $provider = $connection !== null ? $this->calendars->find($connection->provider) : null;

        if ($connection === null || $provider === null || ! $provider->isConfigured()) {
            return [];
        }

        try {
            return $provider->busyTimes($this->connections->ensureFreshToken($connection), $start, $end);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function ensureZoomMeeting(Interview $interview): void
    {
        if ($interview->meeting_provider !== MeetingProvider::Zoom || filled($interview->meeting_link) || ! $this->zoom->isConfigured()) {
            return;
        }

        $result = $this->zoom->createMeeting($interview, self::DEFAULT_DURATION_MINUTES);

        if ($result->ok) {
            $interview->forceFill(['meeting_link' => $result->meetingUrl, 'external_meeting_id' => $result->meetingId])->save();
            AuditLog::record($interview, 'video_meeting_created', null, ['provider' => 'zoom', 'meeting_id' => $result->meetingId]);
        }
    }

    private function eventData(Interview $interview, CalendarConnection $connection): CalendarEventData
    {
        $application = $interview->candidateApplication;
        $candidate = $application->candidate;
        $role = $application->requisition?->designation?->name ?? 'Interview';

        $attendees = array_values(array_filter([
            $this->preferences->blockedReason($candidate, CommunicationChannel::Email) === null ? $candidate->email : null,
        ]));

        return new CalendarEventData(
            title: "Interview: {$candidate->full_name} — {$role} (round {$interview->round_number})",
            start: $interview->scheduled_at,
            end: $interview->scheduled_at->copy()->addMinutes(self::DEFAULT_DURATION_MINUTES),
            description: "Interview for {$role}. Reference {$application->application_code}.".($interview->meeting_link ? "\nJoin: {$interview->meeting_link}" : ''),
            location: $interview->location ?? $interview->meeting_link,
            attendees: $attendees,
            requestConference: $interview->meeting_provider?->calendarProvider() === $connection->provider && blank($interview->meeting_link),
            reference: 'interview-'.$interview->id,
        );
    }
}
