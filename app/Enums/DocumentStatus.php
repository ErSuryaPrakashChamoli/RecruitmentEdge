<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * The single source of truth for this status's badge color (Section 32).
     */
    public function color(): string
    {
        return match ($this) {
            self::Verified => 'success',
            self::Submitted => 'info',
            self::Rejected => 'danger',
            self::Pending => 'gray',
        };
    }
}
