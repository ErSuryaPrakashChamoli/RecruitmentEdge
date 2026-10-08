<?php

namespace App\Enums;

/**
 * Structured separation reason (Phase 8.2 minimal separation record). Deliberately coarse: this
 * is not an HR separation module and records no detailed policy.
 */
enum SeparationReason: string
{
    case Resignation = 'resignation';
    case Termination = 'termination';
    case Abandoned = 'abandoned';
    case Layoff = 'layoff';
    case ContractEnd = 'contract_end';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resignation => 'Resignation',
            self::Termination => 'Termination',
            self::Abandoned => 'Abandoned',
            self::Layoff => 'Layoff',
            self::ContractEnd => 'Contract end',
            self::Other => 'Other',
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
