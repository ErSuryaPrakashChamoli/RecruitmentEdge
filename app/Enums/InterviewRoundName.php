<?php

namespace App\Enums;

/**
 * Stored in interviews.round_name as the label text itself, so every place that echoes round_name
 * (AI tools, notifications, exports) keeps reading naturally.
 */
enum InterviewRoundName: string
{
    case HrRound = 'HR Round';
    case SalesRound = 'Sales Round';
    case OperationsRound = 'Operations Round';
    case FinalRound = 'Final Round';

    public function label(): string
    {
        return $this->value;
    }
}
