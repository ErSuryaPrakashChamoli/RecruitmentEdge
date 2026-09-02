<?php

namespace App\Services\AI\Calendar\Providers;

use App\Services\AI\Calendar\Contracts\CalendarProviderInterface;

/**
 * No Google/Microsoft Calendar OAuth app is registered in this environment — that's a genuine
 * business/credential decision (which provider, which OAuth app) rather than something to invent.
 * Interviews still get scheduled in the app (Interview model) without an external calendar event;
 * createEvent() simply returns null, and callers must treat that as "no external sync happened",
 * never as an error.
 */
class NullCalendarProvider implements CalendarProviderInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function createEvent(string $title, \DateTimeInterface $start, \DateTimeInterface $end, array $attendeeEmails = []): ?string
    {
        return null;
    }
}
