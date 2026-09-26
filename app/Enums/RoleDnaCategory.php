<?php

namespace App\Enums;

/**
 * Grouping of Role DNA attributes.
 */
enum RoleDnaCategory: string
{
    case Identity = 'identity';
    case Skill = 'skill';
    case Experience = 'experience';
    case Education = 'education';
    case Location = 'location';
    case WorkMode = 'work_mode';
    case Compensation = 'compensation';
    case Responsibility = 'responsibility';
    case Behavioral = 'behavioral';
    case Constraint = 'constraint';
    case Sourcing = 'sourcing';
    case InterviewDimension = 'interview_dimension';
    case HistoricalPattern = 'historical_pattern';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Identity',
            self::Skill => 'Skills',
            self::Experience => 'Experience',
            self::Education => 'Education & certifications',
            self::Location => 'Location',
            self::WorkMode => 'Work mode & shift',
            self::Compensation => 'Compensation',
            self::Responsibility => 'Responsibilities',
            self::Behavioral => 'Behavioral indicators',
            self::Constraint => 'Constraints',
            self::Sourcing => 'Sourcing profile',
            self::InterviewDimension => 'Interview dimensions',
            self::HistoricalPattern => 'Historical hiring patterns',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Identity => 'gray',
            self::Skill => 'primary',
            self::Experience => 'primary',
            self::Education => 'primary',
            self::Location => 'gray',
            self::WorkMode => 'gray',
            self::Compensation => 'gray',
            self::Responsibility => 'info',
            self::Behavioral => 'info',
            self::Constraint => 'warning',
            self::Sourcing => 'info',
            self::InterviewDimension => 'info',
            self::HistoricalPattern => 'gray',
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
