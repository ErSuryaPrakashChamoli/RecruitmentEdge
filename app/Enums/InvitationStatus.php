<?php

namespace App\Enums;

/**
 * SaaS-2: where a tenant invitation is in its life. Only Pending can be accepted, and only before
 * it expires; every other state is final (an invitation is never re-opened — a new one is sent).
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Revoked => 'danger',
            self::Expired => 'gray',
        };
    }
}
