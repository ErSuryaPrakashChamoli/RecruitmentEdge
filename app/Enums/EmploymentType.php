<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Contract = 'contract';
    case Temporary = 'temporary';
    case Internship = 'internship';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Contract => 'Contract',
            self::Temporary => 'Temporary',
            self::Internship => 'Internship',
        };
    }
}
