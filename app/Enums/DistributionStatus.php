<?php

namespace App\Enums;

/**
 * State of one job posting on one channel. Published is only set when the connector reported
 * success — a channel without API access stays Failed ("not configured"), never Published.
 */
enum DistributionStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Paused = 'paused';
    case Unpublished = 'unpublished';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Published => 'Published',
            self::Paused => 'Paused',
            self::Unpublished => 'Unpublished',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Published => 'success',
            self::Paused => 'warning',
            self::Unpublished => 'gray',
            self::Failed => 'danger',
        };
    }
}
