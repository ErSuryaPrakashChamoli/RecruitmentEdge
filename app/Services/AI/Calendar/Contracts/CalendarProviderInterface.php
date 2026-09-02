<?php

namespace App\Services\AI\Calendar\Contracts;

interface CalendarProviderInterface
{
    public function isConfigured(): bool;

    /**
     * @return string|null an external event id, if the provider is configured and the event was created
     */
    public function createEvent(string $title, \DateTimeInterface $start, \DateTimeInterface $end, array $attendeeEmails = []): ?string;
}
