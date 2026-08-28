<?php

namespace App\Enums;

enum DuplicateMatchType: string
{
    case Mobile = 'mobile';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'Mobile',
            self::Email => 'Email',
        };
    }
}
