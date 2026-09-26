<?php

namespace App\Enums;

enum ReferralRelationship: string
{
    case FormerColleague = 'former_colleague';
    case Friend = 'friend';
    case Family = 'family';
    case Classmate = 'classmate';
    case ProfessionalNetwork = 'professional_network';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FormerColleague => 'Former colleague',
            self::Friend => 'Friend',
            self::Family => 'Family',
            self::Classmate => 'Classmate',
            self::ProfessionalNetwork => 'Professional network',
            self::Other => 'Other',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
