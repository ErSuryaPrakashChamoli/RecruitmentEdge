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
 */
trait Auditable
{
    /**
     * @var array<int, string>
     */
    private static array $auditExcludedAttributes = ['password', 'remember_token', 'created_at', 'updated_at'];

    protected static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->writeAuditLog('created'));
        static::updated(fn (self $model) => $model->writeAuditLog('updated'));
        static::deleted(fn (self $model) => $model->writeAuditLog('deleted'));
    }

    protected function writeAuditLog(string $action): void
    {
        $excluded = [...self::$auditExcludedAttributes, ...$this->getHidden()];

        [$oldValues, $newValues] = match ($action) {
            'created' => [null, Arr::except($this->getAttributes(), $excluded)],
            'deleted' => [Arr::except($this->getRawOriginal(), $excluded), null],
            default => $this->auditableUpdateDiff($excluded),
        };

        if ($action === 'updated' && $newValues === []) {
            return;
        }

        // Phase 8.3: sensitive values (e.g. offer compensation) are recorded as changed, never
        // copied into the audit log — the model's own history holds them for authorised users.
        $redacted = method_exists($this, 'auditRedactedAttributes') ? $this->auditRedactedAttributes() : [];
        $redact = fn (?array $values): ?array => $values === null ? null : collect($values)->map(fn (mixed $value, string $key) => in_array($key, $redacted, true) && $value !== null ? '[redacted]' : $value)->all();
        [$oldValues, $newValues] = [$redact($oldValues), $redact($newValues)];

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
