<?php

namespace App\Enums;

/**
 * Human review state of an evidence row. Deterministic facts need no verification; AI-derived items start Unverified.
 */
enum VerificationStatus: string
{
    case NotRequired = 'not_required';
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Not required',
            self::Unverified => 'Unverified',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotRequired => 'gray',
            self::Unverified => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
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
