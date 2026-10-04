<?php

namespace App\Services\Lifecycle;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Phase 8.9 (P89-DQ-001…005, DQ-013): lock a row and bring the model up to date from the locking
 * read itself, so a transition decides on the latest committed state.
 *
 * MySQL runs at REPEATABLE READ: a plain read (refresh()) after taking a lock returns the
 * transaction's snapshot, which is stale whenever an earlier plain read — possibly in an outer
 * transaction — fixed it before a concurrent writer committed. A locking read (FOR UPDATE / LOCK IN
 * SHARE MODE) always reads the latest committed row, so the model is rebuilt from that row.
 *
 * Lock order (Phase 8.9, P89-PERF-021): a transaction that locks a candidate application and any of
 * its interviews, offers, joining or incentive calculations always locks the application first.
 */
final class RowLock
{
    /**
     * Locks the model's row (FOR UPDATE) and replaces its attributes with the locked row's. Unsaved
     * local changes are kept on top; cached relations are dropped when the row had changed.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    public static function fresh(Model $model): Model
    {
        if ($model->getConnection()->transactionLevel() === 0) {
            throw new LogicException('RowLock::fresh() must run inside a database transaction.');
        }

        $locked = self::withinTenant($model->newQueryWithoutScopes(), $model)->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
        $changedElsewhere = $locked->getAttributes() != $model->getRawOriginal();
        $dirty = $model->getDirty();

        $model->setRawAttributes($locked->getAttributes(), true);

        if ($dirty !== []) {
            $model->setRawAttributes([...$model->getAttributes(), ...$dirty]);
        }

        if ($changedElsewhere) {
            $model->setRelations([]);
        }

        return $model;
    }

    /**
     * Locks the row by key without loading it into a model the caller holds — for parents whose
     * lock serialises work on their children (e.g. the application before its offers).
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function key(string $modelClass, int|string|null $key): void
    {
        if ($key === null) {
            return;
        }

        self::withinTenant($modelClass::query()->withoutGlobalScopes(), new $modelClass)->whereKey($key)->lockForUpdate()->first();
    }

    /**
     * SaaS-1: locking ignores the other global scopes (soft deletes) but never the tenant: a
     * tenant-owned row is only ever locked inside the current tenant.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function withinTenant(Builder $query, Model $model): Builder
    {
        if (! in_array(BelongsToTenant::class, class_uses_recursive($model), true)) {
            return $query;
        }

        return $query->where($model->qualifyColumn('tenant_id'), TenantContext::current()->requireId());
    }
}
