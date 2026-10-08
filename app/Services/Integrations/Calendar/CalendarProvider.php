<?php

namespace App\Services\Integrations\Calendar;

use App\Models\CalendarConnection;
use App\Services\Integrations\Calendar\Data\CalendarEventData;
use App\Services\Integrations\Calendar\Data\CalendarEventResult;
use App\Services\Integrations\Calendar\Data\OAuthTokens;
use App\Services\Integrations\Contracts\Integration;
use Carbon\CarbonInterface;

/**
 * External calendar adapter (Phase 5; supersedes the Phase 4 AI CalendarProviderInterface
 * extension point for interview sync). The internal scheduling engine (InterviewService /
 * InterviewSchedulingService) stays authoritative — adapters only mirror interviews into an
 * interviewer's calendar and report their busy times. All vendor HTTP calls live in
 * implementations under Calendar/Providers.
 */
interface CalendarProvider extends Integration
{
    public function authorizationUrl(string $state, string $redirectUri): string;

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens;

    public function refresh(CalendarConnection $connection): OAuthTokens;

    public function createEvent(CalendarConnection $connection, CalendarEventData $event): CalendarEventResult;

    public function updateEvent(CalendarConnection $connection, string $externalEventId, CalendarEventData $event): CalendarEventResult;

    public function cancelEvent(CalendarConnection $connection, string $externalEventId): CalendarEventResult;

    public function getEvent(CalendarConnection $connection, string $externalEventId): CalendarEventResult;

    /**
     * Busy intervals in [$start, $end].
     *
     * @return array<int, array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function busyTimes(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end): array;
}
