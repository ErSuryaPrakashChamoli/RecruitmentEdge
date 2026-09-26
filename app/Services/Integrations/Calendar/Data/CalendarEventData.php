<?php

namespace App\Services\Integrations\Calendar\Data;

use Carbon\CarbonInterface;

/**
 * Provider-independent calendar event. `requestConference` asks the provider to attach its own
 * video meeting (Google Meet / Microsoft Teams). Never carries internal notes — only what the
 * attendees (including the candidate) may see.
 */
final readonly class CalendarEventData
{
    /**
     * @param  array<int, string>  $attendees
     */
    public function __construct(
        public string $title,
        public CarbonInterface $start,
        public CarbonInterface $end,
        public string $description,
        public ?string $location = null,
        public array $attendees = [],
        public bool $requestConference = false,
        public string $reference = '',
    ) {}
}
