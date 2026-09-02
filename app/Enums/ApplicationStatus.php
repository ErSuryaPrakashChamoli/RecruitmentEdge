<?php

namespace App\Enums;

/**
 * Rejection/dropout is a status layered on top of `current_stage`, not a stage itself — this way
 * "which stage did they drop at" and "why" are both queryable without terminal stages in the
 * pipeline enum. See StageTransitionService.
 */
enum ApplicationStatus: string
{
    case Active = 'active';
    case Rejected = 'rejected';
    case Dropout = 'dropout';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Rejected => 'Rejected',
            self::Dropout => 'Dropout',
            self::OnHold => 'On Hold',
        };
    }

    /**
     * The single source of truth for this status's badge color — every table column badging this
     * enum should call this rather than redefining its own match arm (Section 32: one badge system,
     * not a new style per page).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Rejected, self::Dropout => 'danger',
            self::OnHold => 'warning',
        };
    }
}
