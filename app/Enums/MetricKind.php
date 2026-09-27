<?php

namespace App\Enums;

/**
 * Business outcomes are kept apart from operational activity (Phase 8.5 §31): an activity count is
 * never presented as an outcome.
 */
enum MetricKind: string
{
    case BusinessOutcome = 'business_outcome';
    case OperationalActivity = 'operational_activity';
    case Quality = 'quality';
    case Efficiency = 'efficiency';
    case AiDerived = 'ai_derived';

    public function label(): string
    {
        return match ($this) {
            self::BusinessOutcome => 'Business outcome',
            self::OperationalActivity => 'Operational activity',
            self::Quality => 'Quality',
            self::Efficiency => 'Efficiency',
            self::AiDerived => 'AI-derived (deterministic)',
        };
    }
}
