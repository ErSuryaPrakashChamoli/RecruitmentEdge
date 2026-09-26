<?php

namespace App\Policies;

use App\Models\HiringMemoryRecord;
use App\Models\RecruitmentRequisition;
use App\Models\User;

/**
 * Hiring Memory follows requisition visibility. Corrections (a new version) need
 * intelligence.memory.manage; records are never edited or deleted.
 */
class HiringMemoryRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('intelligence.memory.view');
    }

    public function view(User $user, HiringMemoryRecord $record): bool
    {
        return $user->can('intelligence.memory.view')
            && ($record->requisition_id === null
                ? $user->can('hierarchy.view-all')
                : RecruitmentRequisition::query()->visibleTo($user)->whereKey($record->requisition_id)->exists());
    }

    public function correct(User $user, HiringMemoryRecord $record): bool
    {
        return $user->can('intelligence.memory.manage') && $this->view($user, $record) && $record->is_current;
    }

    public function update(User $user, HiringMemoryRecord $record): bool
    {
        return false;
    }

    public function delete(User $user, HiringMemoryRecord $record): bool
    {
        return false;
    }
}
