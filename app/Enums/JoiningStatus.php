<?php

namespace App\Enums;

enum JoiningStatus: string
{
    case Expected = 'expected';
    case Confirmed = 'confirmed';
    case Joined = 'joined';
    case NoShow = 'no_show';
    case Dropout = 'dropout';

    public function label(): string
    {
        return match ($this) {
            self::Expected => 'Expected',
            self::Confirmed => 'Confirmed',
            self::Joined => 'Joined',
            self::NoShow => 'No Show',
            self::Dropout => 'Dropout',
        };
    }
}
