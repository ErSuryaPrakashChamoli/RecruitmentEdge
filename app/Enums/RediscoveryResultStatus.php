<?php

namespace App\Enums;

/**
 * What a person decided about one rediscovered candidate.
 */
enum RediscoveryResultStatus: string
{
    case Suggested = 'suggested';
    case AddedToRequisition = 'added_to_requisition';
    case AddedToPool = 'added_to_pool';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Suggested',
            self::AddedToRequisition => 'Added to requisition',
            self::AddedToPool => 'Added to talent pool',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Suggested => 'info',
            self::AddedToRequisition => 'success',
            self::AddedToPool => 'success',
            self::Dismissed => 'gray',
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
