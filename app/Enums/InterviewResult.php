<?php

namespace App\Enums;

/**
 * Set only once the interview is Completed. Kept separate from InterviewStatus (Section 15 lists
 * "Selected"/"Rejected" alongside workflow states like "Scheduled"/"Cancelled" in one flat list —
 * splitting them into status (workflow) vs result (decision) avoids a Completed interview needing
 * to also somehow be "Scheduled").
 */
enum InterviewResult: string
{
    case Selected = 'selected';
    case Rejected = 'rejected';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::Selected => 'Selected',
            self::Rejected => 'Rejected',
            self::OnHold => 'On Hold',
        };
    }

    /**
     * The single source of truth for this result's badge color (Section 32).
     */
    public function color(): string
    {
        return match ($this) {
            self::Selected => 'success',
            self::Rejected => 'danger',
            self::OnHold => 'warning',
        };
    }
}
