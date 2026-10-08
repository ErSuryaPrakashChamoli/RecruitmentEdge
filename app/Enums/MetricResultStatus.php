<?php

namespace App\Enums;

/**
 * Why a governed metric does or does not carry a value (Phase 8.5). Zero is a value; none of the
 * other states is ever shown as zero.
 */
enum MetricResultStatus: string
{
    case Ok = 'ok';
    case NoData = 'no_data';
    case Unknown = 'unknown';
    case InsufficientSample = 'insufficient_sample';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Available',
            self::NoData => 'No data in period',
            self::Unknown => 'Unknown (records exist but none could be measured)',
            self::InsufficientSample => 'Insufficient sample',
            self::NotApplicable => 'Not applicable',
        };
    }

    /**
     * The short text shown in place of a withheld value.
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::Ok => '',
            self::NoData => '—',
            self::Unknown => 'Unknown',
            self::InsufficientSample => 'Too few',
            self::NotApplicable => 'N/A',
        };
    }
}
