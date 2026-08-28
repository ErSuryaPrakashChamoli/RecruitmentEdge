<?php

namespace App\Enums;

enum DuplicateMatchStatus: string
{
    case PendingReview = 'pending_review';
    case ConfirmedDuplicate = 'confirmed_duplicate';
    case NotDuplicate = 'not_duplicate';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending Review',
            self::ConfirmedDuplicate => 'Confirmed Duplicate',
            self::NotDuplicate => 'Not a Duplicate',
        };
    }
}
