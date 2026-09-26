<?php

namespace App\Enums;

/**
 * How an outcome record came to exist (Phase 8.2).
 */
enum OutcomeCaptureMode: string
{
    case ObservedGoingForward = 'observed_going_forward';
    case BackfilledDeterministic = 'backfilled_deterministic';
    case ManualCorrection = 'manual_correction';

    public function label(): string
    {
        return match ($this) {
            self::ObservedGoingForward => 'Observed going forward',
            self::BackfilledDeterministic => 'Backfilled (deterministic records)',
            self::ManualCorrection => 'Manual correction',
        };
    }
}
