<?php

namespace App\Services\Integrations\Calendar\Data;

/**
 * Normalized provider answer for an event operation.
 */
final readonly class CalendarEventResult
{
    public function __construct(
        public bool $ok,
        public ?string $externalEventId = null,
        public ?string $meetingUrl = null,
        public ?string $meetingId = null,
        public ?string $error = null,
        public bool $retryable = false,
    ) {}

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(false, error: $error, retryable: $retryable);
    }
}
