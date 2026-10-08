<?php

namespace App\Enums;

/**
 * Why a message was sent. Event and Reminder messages come from listeners/scheduled commands
 * (Phase 5E); Ai from an approved AI action; Automation from an active automation rule (Phase 6).
 */
enum CommunicationTrigger: string
{
    case Manual = 'manual';
    case Event = 'event';
    case Reminder = 'reminder';
    case Ai = 'ai';
    case Automation = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Event => 'Automatic (event)',
            self::Reminder => 'Scheduled reminder',
            self::Ai => 'AI action (approved)',
            self::Automation => 'Automation rule',
        };
    }
}
