<?php

namespace App\Enums;

/**
 * Lifecycle of one rule run against one entity (see AutomationEngine).
 */
enum AutomationExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::PartiallyCompleted => 'Partially completed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::Completed => 'success',
            self::PartiallyCompleted => 'warning',
            self::Failed => 'danger',
            self::Skipped => 'gray',
            self::Cancelled => 'gray',
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
