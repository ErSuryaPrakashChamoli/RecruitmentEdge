<?php

namespace App\Services\AI\Tools\Concerns;

use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Support\Collection;

/**
 * Every tool touching candidate/requisition/recruiter data must resolve visibility through
 * HierarchyService — exactly like the Filament UI does — rather than inventing separate "AI
 * scoping" rules (spec section 27).
 */
trait ScopesToHierarchy
{
    /**
     * @return Collection<int, int>|null null means unrestricted (hierarchy.view-all)
     */
    protected function visibleEmployeeIds(User $user): ?Collection
    {
        return app(HierarchyService::class)->visibleEmployeeIdsFor($user);
    }
}
