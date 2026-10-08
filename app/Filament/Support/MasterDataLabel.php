<?php

namespace App\Filament\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.6 (D8.6-004): a master-data name shown on a record keeps showing after the master data
 * is archived, marked "(archived)" so nobody mistakes it for a current option.
 *
 * Usage: TextColumn::make('department.name')->formatStateUsing(MasterDataLabel::for('department'))
 */
class MasterDataLabel
{
    /**
     * @return Closure(?string, Model): ?string
     */
    public static function for(string $relationPath): Closure
    {
        return function (?string $state, Model $record) use ($relationPath): ?string {
            $related = data_get($record, $relationPath);

            return $state !== null && $related instanceof Model && method_exists($related, 'trashed') && $related->trashed()
                ? "{$state} (archived)"
                : $state;
        };
    }
}
