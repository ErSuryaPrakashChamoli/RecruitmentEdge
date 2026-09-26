<?php

namespace App\Models\Concerns;

use App\Services\Lifecycle\LifecycleGuard;
use LogicException;

/**
 * Phase 8.3: refuses an update that changes a lifecycle attribute outside its authoritative
 * service (see LifecycleGuard). The using model declares the attributes in lifecycleAttributes()
 * and names the service that owns them in lifecycleOwner().
 */
trait GuardsLifecycleAttributes
{
    protected static function bootGuardsLifecycleAttributes(): void
    {
        static::updating(function (self $model): void {
            if (LifecycleGuard::isOpen()) {
                return;
            }

            $blocked = array_values(array_intersect(array_keys($model->getDirty()), $model->lifecycleAttributes()));

            if ($blocked !== []) {
                throw new LogicException(class_basename($model).' '.implode(', ', $blocked).' can only change through '.$model->lifecycleOwner().'.');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    abstract public function lifecycleAttributes(): array;

    abstract public function lifecycleOwner(): string;
}
