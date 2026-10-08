<?php

namespace App\Enums;

/**
 * SaaS-5: a tenant deletion request. Requested → Approved (the tenant is deletion-pending and the
 * grace period runs; cancellable) → Purging → Purged. Cancelled ends it before the purge starts;
 * Failed is a purge that stopped (it resumes on retry).
 */
enum DeletionRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
    case Purging = 'purging';
    case Purged = 'purged';
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Purging, self::Failed], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved, self::Purging, self::Failed => 'danger',
            self::Cancelled, self::Purged => 'gray',
        };
    }
}
