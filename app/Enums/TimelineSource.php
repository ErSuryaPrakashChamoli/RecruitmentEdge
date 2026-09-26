<?php

namespace App\Enums;

/**
 * Where a timeline event originated. Integration is reserved for Phase 5 provider callbacks
 * (email/WhatsApp/SMS delivery, calendar sync); Ai for Phase 7 automated actions.
 */
enum TimelineSource: string
{
    case Recruiter = 'recruiter';
    case CandidatePortal = 'candidate_portal';
    case Employee = 'employee';
    case System = 'system';
    case Integration = 'integration';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Recruiter => 'Recruiter',
            self::CandidatePortal => 'Candidate portal',
            self::Employee => 'Employee',
            self::System => 'System',
            self::Integration => 'Integration',
            self::Ai => 'AI',
        };
    }
}
