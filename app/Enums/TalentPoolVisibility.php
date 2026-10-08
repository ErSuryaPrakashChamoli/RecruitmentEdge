<?php

namespace App\Enums;

/**
 * Who can see a talent pool, on top of hierarchy visibility (the owner and everyone above the
 * owner can always see it; CHRO sees all):
 * - Private: only that chain.
 * - Team: also everyone below the owner.
 * - Organization: anyone with talent-pools.viewAny.
 */
enum TalentPoolVisibility: string
{
    case Private = 'private';
    case Team = 'team';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Team => 'Team',
            self::Organization => 'Organization',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private => 'Owner and their management chain',
            self::Team => 'Owner, their management chain and their team',
            self::Organization => 'Everyone with access to talent pools',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $v) => [$v->value => $v->label()])->all();
    }
}
