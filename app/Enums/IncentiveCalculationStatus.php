<?php

namespace App\Enums;

/**
 * Section 27's lifecycle. A calculation sits in Calculated (not yet PendingVerification) only
 * while a configured retention period hasn't yet elapsed — see
 * RecruiterIncentiveCalculator::isRetentionSatisfied() and the `incentives:release-matured`
 * command.
 */
enum IncentiveCalculationStatus: string
{
    case Calculated = 'calculated';
    case PendingVerification = 'pending_verification';
    case Approved = 'approved';
    case Payable = 'payable';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'Calculated',
            self::PendingVerification => 'Pending Verification',
            self::Approved => 'Approved',
            self::Payable => 'Payable',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Reversed => 'Reversed',
        };
    }

    /**
     * The single source of truth for this status's badge color (Section 32).
     */
    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Payable, self::Approved => 'info',
            self::PendingVerification, self::Calculated => 'warning',
            self::Rejected, self::Reversed => 'danger',
        };
    }
}
