<?php

namespace App\Enums;

/**
 * Lifecycle of an outcome record (Phase 8.2). Changes go through OutcomeService only; see
 * canTransitionTo() for the allowed moves. Unknown → Confirmed is a human correction resolving
 * what the rules could not observe.
 */
enum OutcomeState: string
{
    case Pending = 'pending';
    case Observing = 'observing';
    case Observed = 'observed';
    case Confirmed = 'confirmed';
    case Unknown = 'unknown';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Observing => 'Observing',
            self::Observed => 'Observed',
            self::Confirmed => 'Confirmed',
            self::Unknown => 'Unknown',
            self::Void => 'Void',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Pending => [self::Observing, self::Observed, self::Unknown, self::Void],
            self::Observing => [self::Observed, self::Unknown, self::Void],
            self::Observed => [self::Confirmed, self::Void],
            self::Unknown => [self::Observed, self::Confirmed, self::Void],
            self::Confirmed => [self::Void],
            self::Void => [],
        }, true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Observed, self::Confirmed => 'success',
            self::Pending, self::Observing => 'info',
            self::Unknown => 'gray',
            self::Void => 'danger',
        };
    }
}
