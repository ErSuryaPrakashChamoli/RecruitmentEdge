<?php

namespace App\Services\Integrations\Calendar;

use App\Services\Integrations\Calendar\Providers\GoogleCalendarProvider;
use App\Services\Integrations\Calendar\Providers\MicrosoftCalendarProvider;

/**
 * The calendar adapters the platform implements. The only place that maps a provider key to a
 * vendor class.
 */
class CalendarManager
{
    /**
     * @var array<string, class-string<CalendarProvider>>
     */
    public const array PROVIDERS = [
        'google_calendar' => GoogleCalendarProvider::class,
        'microsoft_calendar' => MicrosoftCalendarProvider::class,
    ];

    public function find(string $key): ?CalendarProvider
    {
        return isset(self::PROVIDERS[$key]) ? app(self::PROVIDERS[$key]) : null;
    }
}
