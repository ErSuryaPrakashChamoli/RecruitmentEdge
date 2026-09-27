<?php

namespace App\Filament\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.6 (D8.6-004/005): master-data relations resolve archived records so history keeps its
 * labels; a form picker built on such a relation must still offer only active, unarchived records
 * — plus the value the record already holds, so an existing record can be saved unchanged.
 *
 * Usage: Select::make('department_id')->relationship('department', 'name', ActiveMasterDataOptions::scope('department_id'))
 */
class ActiveMasterDataOptions
{
    /**
     * @return Closure(Builder, ?Model): Builder
     */
    public static function scope(string $column): Closure
    {
        return function (Builder $query, ?Model $record) use ($column): Builder {
            $current = $record?->getAttribute($column);
            $table = $query->getModel()->getTable();

            return $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where("{$table}.is_active", true)->whereNull("{$table}.deleted_at"))
                ->when($current !== null, fn (Builder $query) => $query->orWhere($query->getModel()->getQualifiedKeyName(), $current)));
        };
    }
}
