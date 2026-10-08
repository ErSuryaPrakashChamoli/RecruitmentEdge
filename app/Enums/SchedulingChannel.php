<?php

namespace App\Enums;

/**
 * How a booking was made. Integration is reserved for Phase 5 external calendar / scheduling
 * providers booking on the candidate's behalf.
 */
enum SchedulingChannel: string
{
    case CandidatePortal = 'candidate_portal';
    case SignedLink = 'signed_link';
    case Recruiter = 'recruiter';
    case Integration = 'integration';

    public function label(): string
    {
        return match ($this) {
            self::CandidatePortal => 'Candidate portal',
            self::SignedLink => 'Scheduling link',
            self::Recruiter => 'Recruiter',
            self::Integration => 'Integration',
        };
    }
}
