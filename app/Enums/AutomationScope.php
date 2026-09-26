<?php

namespace App\Enums;

/**
 * Which records an automation rule applies to. Organization scope requires the automation.organization permission; every other scope must fall inside the author's hierarchy.
 */
enum AutomationScope: string
{
    case Organization = 'organization';
    case Department = 'department';
    case Location = 'location';
    case Requisition = 'requisition';
    case Team = 'team';
    case Recruiter = 'recruiter';

    public function label(): string
    {
        return match ($this) {
            self::Organization => 'Organization',
            self::Department => 'Department',
            self::Location => 'Location',
            self::Requisition => 'Requisition',
            self::Team => 'Recruitment team',
            self::Recruiter => 'Recruiter',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Organization => 'primary',
            self::Department => 'info',
            self::Location => 'info',
            self::Requisition => 'info',
            self::Team => 'info',
            self::Recruiter => 'gray',
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
