<?php

namespace App\Enums;

/**
 * Who hosts an interview's video meeting. Google Meet and Microsoft Teams links are created by
 * the interviewer's connected calendar (conference on the calendar event); Zoom by the Zoom
 * adapter; Manual means the recruiter pasted a link.
 */
enum MeetingProvider: string
{
    case Manual = 'manual';
    case GoogleMeet = 'google_meet';
    case MicrosoftTeams = 'microsoft_teams';
    case Zoom = 'zoom';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual link',
            self::GoogleMeet => 'Google Meet',
            self::MicrosoftTeams => 'Microsoft Teams',
            self::Zoom => 'Zoom',
        };
    }

    /**
     * The calendar provider that creates this meeting as part of the calendar event, if any.
     */
    public function calendarProvider(): ?string
    {
        return match ($this) {
            self::GoogleMeet => 'google_calendar',
            self::MicrosoftTeams => 'microsoft_calendar',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $p) => [$p->value => $p->label()])->all();
    }
}
