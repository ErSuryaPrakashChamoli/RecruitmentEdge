<?php

namespace App\Enums;

/**
 * Phase 8.4: whether a staff login may be used — separate from employment (EmployeeStatus).
 * Active may sign in and act; Suspended is a reversible block (employment inactive, or an admin
 * decision) that keeps roles dormant; Revoked ends authority (separation or an admin decision):
 * roles are removed and restoring grants only the base role.
 */
enum AccessState: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Revoked => 'danger',
        };
    }
}
