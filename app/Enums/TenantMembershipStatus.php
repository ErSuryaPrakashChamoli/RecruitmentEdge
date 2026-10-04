<?php

namespace App\Enums;

/**
 * SaaS-1: whether a staff identity (users row) belongs to a tenant. Access inside the tenant is
 * still governed by the identity's AccessState and roles; a Revoked membership ends access to that
 * tenant only.
 */
enum TenantMembershipStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
        };
    }
}
