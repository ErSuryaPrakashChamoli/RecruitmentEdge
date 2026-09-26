<?php

namespace App\Enums;

/**
 * Priority shared by Action Center items and Notification Center entries (Low is shown as Informational for notifications).
 */
enum ActionPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'warning',
            self::Medium => 'info',
            self::Low => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }

    /**
     * Notifications raised before Phase 6 carry only a color; this maps it to a priority.
     */
    public static function fromColor(string $color): self
    {
        return match ($color) {
            'danger' => self::Critical,
            'warning' => self::High,
            'info', 'success' => self::Low,
            default => self::Medium,
        };
    }

    public function notificationColor(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'warning',
            self::Medium => 'info',
            self::Low => 'gray',
        };
    }

    public function notificationLabel(): string
    {
        return $this === self::Low ? 'Informational' : $this->label();
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }
}
