<?php

namespace App\Enums;

/**
 * Overall Hiring Health of a requisition, derived from its metrics.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Watch = 'watch';
    case AtRisk = 'at_risk';
    case Critical = 'critical';
    case InsufficientData = 'insufficient_data';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Watch => 'Watch',
            self::AtRisk => 'At risk',
            self::Critical => 'Critical',
            self::InsufficientData => 'Insufficient data',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Watch => 'info',
            self::AtRisk => 'warning',
            self::Critical => 'danger',
            self::InsufficientData => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
