<?php

namespace App\Enums;

enum InterviewRoundNumber: int
{
    case First = 1;
    case Second = 2;
    case Third = 3;
    case Fourth = 4;
    case Final = 5;

    public function label(): string
    {
        return match ($this) {
            self::First => 'First',
            self::Second => 'Second',
            self::Third => 'Third',
            self::Fourth => 'Fourth',
            self::Final => 'Final',
        };
    }
}
