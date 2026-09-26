<?php

namespace App\Enums;

/**
 * What an outcome record says happened (Phase 8.2). Missing evidence is never a failure:
 * NotObserved, Unknown and NotApplicable are distinct from every observed result.
 */
enum OutcomeResult: string
{
    case Occurred = 'occurred';
    case Measured = 'measured';
    case Active = 'active';
    case Inactive = 'inactive';
    case SeparatedBeforeCheckpoint = 'separated_before_checkpoint';
    case NotObserved = 'not_observed';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Occurred => 'Occurred',
            self::Measured => 'Measured',
            self::Active => 'Observed active',
            self::Inactive => 'Observed inactive (not confirmed as an exit)',
            self::SeparatedBeforeCheckpoint => 'Separated before checkpoint',
            self::NotObserved => 'Not observed',
            self::Unknown => 'Unknown',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function isObserved(): bool
    {
        return ! in_array($this, [self::NotObserved, self::Unknown, self::NotApplicable], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Occurred, self::Measured, self::Active => 'success',
            self::Inactive => 'warning',
            self::SeparatedBeforeCheckpoint => 'danger',
            self::NotObserved, self::Unknown, self::NotApplicable => 'gray',
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
