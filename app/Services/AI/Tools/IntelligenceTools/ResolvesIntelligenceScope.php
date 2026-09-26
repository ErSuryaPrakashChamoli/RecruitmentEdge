<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Models\RecruitmentRequisition;
use App\Models\User;

/**
 * EDGE Intelligence tools resolve requisitions only through the shared requisition visibility
 * scope (RecruitmentRequisition::visibleTo) — the same rule as the Filament UI.
 */
trait ResolvesIntelligenceScope
{
    protected function visibleRequisition(User $user, mixed $id): ?RecruitmentRequisition
    {
        return blank($id) ? null : RecruitmentRequisition::query()->visibleTo($user)->find($id);
    }
}
