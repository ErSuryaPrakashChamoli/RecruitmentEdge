<?php

namespace App\Enums;

/**
 * SaaS-1: where a tenant (one customer organisation) is in its lifecycle. Only Trial, Active and
 * PastDue are usable: staff may sign in, the careers site and portal answer, and queued or
 * scheduled work runs. Every other state fails closed. Billing, grace periods and read-only
 * suspension belong to later phases; they will refine these rules, never widen them silently.
 */
enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case DeletionPending = 'deletion_pending';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Provisioning',
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
            self::DeletionPending => 'Deletion pending',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Staff may sign in to the tenant, and its public pages (careers, candidate portal) answer.
     */
    public function isUsable(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::PastDue], true);
    }

    /**
     * Queued jobs, listeners and scheduled tasks may run for the tenant.
     */
    public function allowsBackgroundWork(): bool
    {
        return $this->isUsable();
    }
}
