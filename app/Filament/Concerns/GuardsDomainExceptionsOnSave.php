<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * For Create/Edit record pages whose model refuses a change with a DomainException (Phase 8.6
 * governance guards: locked incentive terms, overlapping targets, reversed effective ranges): the
 * refusal is shown as a notification and the save halts, instead of a 500 page.
 */
trait GuardsDomainExceptionsOnSave
{
    use GuardsDomainExceptions;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return static::guarded('Not saved', fn (): Model => parent::handleRecordCreation($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::guarded('Not saved', fn (): Model => parent::handleRecordUpdate($record, $data));
    }
}
