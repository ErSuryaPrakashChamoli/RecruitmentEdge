<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

/**
 * Writes an AuditLog row on create/update/delete (Section 41). Only attach this to models that
 * don't already have a dedicated immutable history table of their own — see AuditLog's docblock.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->writeAuditLog('created'));
        static::updated(fn (self $model) => $model->writeAuditLog('updated'));
        static::deleted(fn (self $model) => $model->writeAuditLog('deleted'));
    }

    protected function writeAuditLog(string $action): void
    {
        $changes = null;

        if ($action === 'updated') {
            $changes = Arr::except($this->getChanges(), ['password', 'remember_token', 'updated_at']);

            if ($changes === []) {
                return;
            }
        }

        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'action' => $action,
            'changes' => $changes,
            'ip_address' => request()?->ip(),
        ]);
    }
}
