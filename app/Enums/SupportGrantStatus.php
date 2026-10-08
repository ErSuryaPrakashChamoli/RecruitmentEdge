<?php

namespace App\Enums;

/**
 * SaaS-5: a support grant's state. Requested by a support operator, or granted directly by the
 * tenant; Active only between its start and end; Denied, Revoked and Expired are final.
 */
enum SupportGrantStatus: string
{
    case Requested = 'requested';
    case Active = 'active';
    case Denied = 'denied';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Active => 'success',
            self::Denied => 'danger',
            self::Revoked, self::Expired => 'gray',
        };
    }
}
