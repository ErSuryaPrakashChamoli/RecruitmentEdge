<?php

namespace App\Enums;

/**
 * SaaS-4: the canonical state of one payment. A state only moves forward (rank): a late "failed"
 * never undoes "succeeded", and a provider may still turn a failed attempt into a success.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Succeeded = 'succeeded';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Failed => 1,
            self::Succeeded => 2,
            self::PartiallyRefunded => 3,
            self::Refunded => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Processing',
            self::Failed => 'Failed',
            self::Succeeded => 'Paid',
            self::PartiallyRefunded => 'Partly refunded',
            self::Refunded => 'Refunded',
        };
    }
}
