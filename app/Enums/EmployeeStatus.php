<?php

namespace App\Enums;

/**
 * Employment state. Phase 8.4: Separated is set only by EmployeeLifecycleService when a separation
 * becomes effective (the day after the last working day); Inactive is a reversible employment
 * pause. Access follows employment: Active → access Active, Inactive → Suspended, Separated →
 * Revoked (StaffAccessService).
 */
enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Separated = 'separated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Separated => 'Separated',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
            self::Separated => 'danger',
        };
    }
}
