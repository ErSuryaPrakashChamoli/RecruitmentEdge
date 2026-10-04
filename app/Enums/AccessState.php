<?php

namespace App\Enums;

/**
 * Phase 8.4 / SaaS-2: whether a staff membership — a person's access to one tenant
 * (TenantMembership.status) — may be used. Separate from employment (EmployeeStatus).
 * Active may sign in to the tenant and act; Suspended is a reversible block (employment inactive,
 * or an administrator's decision) that keeps the tenant's roles dormant; Revoked ends authority in
 * the tenant: its roles are removed and restoring grants only the base role. Each tenant's
 * membership has its own state: one tenant never suspends or revokes a person's access to another.
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
