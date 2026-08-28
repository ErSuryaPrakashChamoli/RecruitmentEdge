<?php

namespace App\Enums;

enum IncentiveAdjustmentType: string
{
    case Correction = 'correction';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Correction => 'Correction',
            self::Reversal => 'Reversal',
        };
    }
}
