<?php

namespace App\Enums;

/**
 * Internal events are only ever shown inside the admin panel. Candidate events may also be shown
 * in the candidate portal — never mark anything containing recruiter notes, scores, salary
 * discussions or internal reasons as Candidate.
 */
enum TimelineVisibility: string
{
    case Internal = 'internal';
    case Candidate = 'candidate';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal only',
            self::Candidate => 'Visible to candidate',
        };
    }
}
