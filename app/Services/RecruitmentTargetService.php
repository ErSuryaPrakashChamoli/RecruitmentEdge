<?php

namespace App\Services;

use App\Models\RecruitmentDailyTarget;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 8.9 (P89-SEC-001): the only writer of recruitment targets. Every create, edit and delete —
 * single or bulk, from any page — is authorized here against RecruitmentDailyTargetPolicy and the
 * target scope rule (RecruitmentDailyTarget::isVisibleTo), so a manager or VP HR can never create,
 * change or delete a target outside their hierarchy, nor a department/designation target without
 * hierarchy.view-all, whatever the UI shows. The model's own guards (one scope, no overlap) and its
 * Auditable trail are unchanged.
 */
class RecruitmentTargetService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): RecruitmentDailyTarget
    {
        Gate::forUser($actor)->authorize('create', RecruitmentDailyTarget::class);

        $target = new RecruitmentDailyTarget([...$attributes, 'created_by' => $actor->employee_id]);
        $this->authorizeScope($actor, $target);
        $target->save();

        return $target;
    }

    /**
     * Both the target as it is and as it would become must be inside the actor's scope.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, RecruitmentDailyTarget $target, array $attributes): RecruitmentDailyTarget
    {
        Gate::forUser($actor)->authorize('update', $target);

        unset($attributes['created_by']);
        $target->fill($attributes);
        $this->authorizeScope($actor, $target);
        $target->save();

        return $target;
    }

    public function delete(User $actor, RecruitmentDailyTarget $target): bool
    {
        Gate::forUser($actor)->authorize('delete', $target);

        return (bool) $target->delete();
    }

    /**
     * Deletes each target the actor may delete, in one transaction per target, and returns how many
     * were deleted. A target outside the actor's scope is refused, never deleted.
     *
     * @param  iterable<RecruitmentDailyTarget>  $targets
     */
    public function deleteMany(User $actor, iterable $targets): int
    {
        $deleted = 0;

        foreach ($targets as $target) {
            if (Gate::forUser($actor)->denies('delete', $target)) {
                continue;
            }

            $deleted += DB::transaction(fn (): int => (int) $target->delete());
        }

        return $deleted;
    }

    private function authorizeScope(User $actor, RecruitmentDailyTarget $target): void
    {
        if (! $target->isVisibleTo($actor)) {
            throw new AuthorizationException('You can only set targets for recruiters in your own team; department and designation targets need organisation-wide access.');
        }
    }
}
