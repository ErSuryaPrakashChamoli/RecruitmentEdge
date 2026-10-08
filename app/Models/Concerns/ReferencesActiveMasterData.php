<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8.6 (D8.6-005): a model may only take up master data that is in service. On create, and
 * whenever one of the listed foreign keys changes, the referenced record must exist, be active and
 * not archived — whichever path writes the model (forms, services, tools, imports). A value that
 * does not change on edit stays valid, so a record keeps its historical department even after
 * that department is deactivated or archived.
 *
 * The model declares activeMasterDataReferences(): foreign key => master-data model class.
 */
trait ReferencesActiveMasterData
{
    protected static function bootReferencesActiveMasterData(): void
    {
        static::saving(function (Model $model): void {
            foreach ($model->activeMasterDataReferences() as $column => $class) {
                $id = $model->getAttribute($column);

                if ($id === null || ($model->exists && ! $model->isDirty($column))) {
                    continue;
                }

                $referenced = $class::withTrashed()->find($id);

                if ($referenced === null || $referenced->trashed() || ! $referenced->is_active) {
                    $label = Str::of(class_basename($class))->snake(' ')->replace('recruitment ', '')->toString();

                    throw ValidationException::withMessages([
                        $column => "The selected {$label} is inactive or archived and can't be used for new records.",
                    ]);
                }
            }
        });
    }

    /**
     * @return array<string, class-string<Model>>
     */
    abstract public function activeMasterDataReferences(): array;
}
