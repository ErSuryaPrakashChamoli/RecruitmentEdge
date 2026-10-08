<?php

namespace App\Services\Integrations\Video;

use App\Models\Interview;
use App\Services\Integrations\Calendar\Data\CalendarEventResult;
use App\Services\Integrations\Contracts\Integration;

/**
 * A standalone video-meeting provider (Phase 5) — one that creates meetings without a calendar
 * event (Zoom). Google Meet and Microsoft Teams meetings are created by the interviewer's
 * calendar adapter instead (conference on the calendar event). Credentials never touch the
 * Interview record; only the join URL and external meeting id are stored there.
 */
interface VideoMeetingProvider extends Integration
{
    public function createMeeting(Interview $interview, int $durationMinutes): CalendarEventResult;

    public function cancelMeeting(string $meetingId): bool;
}
