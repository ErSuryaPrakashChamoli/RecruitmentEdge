<?php

namespace App\Models\Concerns;

use DomainException;

/**
 * Phase 8.6 master-data lifecycle (D8.6-001/007): Active → Inactive → Archived, never deleted.
 *
 * - The code is immutable once created: it identifies the record in history, seeders and
 *   integrations (a rename changes only the display name).
 * - A permanent (force) delete is refused at the model — database cascades would otherwise delete
 *   costs, targets and incentive rules and blank frozen outcome snapshots, unaudited.
 * - Archiving is a soft delete, audited as `archived`; history keeps resolving the record.
 *
 * Changes go through MasterDataLifecycleService (reasons, in-use checks, authorization). Models
 * using this trait also use Auditable.
 */
trait GovernedMasterData
{
    protected static function bootGovernedMasterData(): void
    {
        static::updating(function (self $model): void {
            if ($model->isDirty('code') && filled($model->getOriginal('code'))) {
                throw new DomainException('A code cannot be changed once created — history and integrations refer to it. Change the name instead.');
            }
        });

        static::forceDeleting(function (): void {
            throw new DomainException('Master data is archived, never permanently deleted — historical records still refer to it.');
        });
    }

    public function auditSoftDeleteAction(): string
    {
        return 'archived';
    }

    public function isArchived(): bool
    {
        return $this->trashed();
    }

    public function lifecycleState(): string
    {
        return match (true) {
            $this->trashed() => 'Archived',
            (bool) $this->is_active => 'Active',
            default => 'Inactive',
        };
    }

    /**
     * The record's name for display, marked when it has been archived (D8.6-004).
     */
    public function displayName(): string
    {
        return $this->trashed() ? "{$this->name} (archived)" : (string) $this->name;
    }
}
