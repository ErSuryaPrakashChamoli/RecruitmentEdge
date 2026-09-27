<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

/**
 * Writes an AuditLog row on create/update/delete (Section 41), with both sides of every change:
 * `old_values` holds the previous raw values and `changes` the new ones (created: new values only;
 * deleted: old values only). Only attach this to models that don't already have a dedicated
 * immutable history table of their own — see AuditLog's docblock. A model may declare
 * auditRedactedAttributes(): those keys are logged as "[redacted]" when they change.
 *
 * Phase 8.6 (D8.6-024): a soft-deleted model's restore is logged once as `restored` (not as an
 * update of deleted_at) and a permanent delete as `force_deleted`, so the two are never confused
 * with an ordinary `deleted`. A change to a hidden attribute (e.g. an OAuth token) is recorded as
 * "[changed]" without its value, so it is visible that it happened — except attributes a model
 * lists in auditDerivedAttributes() (recomputed from other audited fields), which are left out.
 * A reason given through AuditLog::withReason() is stored on every row.
 */
trait Auditable
{
    /**
     * Never logged, not even as "[changed]" — passwords have their own explicit audit events.
     *
     * @var array<int, string>
     */
    private static array $auditExcludedAttributes = ['password', 'remember_token', 'created_at', 'updated_at'];

    private bool $auditRestoring = false;

    protected static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->writeAuditLog('created'));
        static::updated(fn (self $model) => $model->auditRestoring ? null : $model->writeAuditLog('updated'));
        static::deleted(fn (self $model) => $model->writeAuditLog(match (true) {
            method_exists($model, 'isForceDeleting') && $model->isForceDeleting() => 'force_deleted',
            method_exists($model, 'auditSoftDeleteAction') => $model->auditSoftDeleteAction(),
            default => 'deleted',
        }));

        if (method_exists(static::class, 'restoring')) {
            static::restoring(function (self $model): void {
                $model->auditRestoring = true;
            });
            static::restored(function (self $model): void {
                $model->auditRestoring = false;
                AuditLog::record($model, 'restored', ['deleted_at' => 'archived'], ['deleted_at' => null]);
            });
        }
    }

    protected function writeAuditLog(string $action): void
    {
        $derived = method_exists($this, 'auditDerivedAttributes') ? $this->auditDerivedAttributes() : [];
        $hidden = array_diff($this->getHidden(), self::$auditExcludedAttributes, $derived);
        $excluded = [...self::$auditExcludedAttributes, ...$this->getHidden()];

        [$oldValues, $newValues] = match ($action) {
            'created' => [null, Arr::except($this->getAttributes(), $excluded)],
            'updated' => $this->auditableUpdateDiff($excluded),
            default => [Arr::except($this->getRawOriginal(), $excluded), null],
        };

        if ($action === 'updated') {
            $changedHidden = array_values(array_intersect($hidden, array_keys($this->getChanges())));

            foreach ($changedHidden as $key) {
                $oldValues[$key] = '[hidden]';
                $newValues[$key] = '[changed]';
            }

            if ($newValues === []) {
                return;
            }
        }

        // Phase 8.3: sensitive values (e.g. offer compensation) are recorded as changed, never
        // copied into the audit log — AuditLog::record() redacts the model's redacted attributes.
        AuditLog::record($this, $action, $oldValues, $newValues);
    }

    /**
     * Called from the `updated` event, before Eloquent re-syncs the original attributes — so
     * getRawOriginal() still holds the pre-update values of each changed key.
     *
     * @param  array<int, string>  $excluded
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function auditableUpdateDiff(array $excluded): array
    {
        $newValues = Arr::except($this->getChanges(), $excluded);
        $oldValues = collect($newValues)
            ->mapWithKeys(fn (mixed $value, string $key) => [$key => $this->getRawOriginal($key)])
            ->all();

        return [$oldValues, $newValues];
    }
}
