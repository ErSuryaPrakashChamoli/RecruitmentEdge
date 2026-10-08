<?php

namespace App\Enums;

/**
 * Evidence quality behind an outcome (Phase 8.2) — set by the rule that produced it, never by AI.
 */
enum OutcomeConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High — deterministic record',
            self::Medium => 'Medium — status observed on the check date',
            self::Low => 'Low — indirect evidence',
        };
    }
}
