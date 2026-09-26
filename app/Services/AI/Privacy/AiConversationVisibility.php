<?php

namespace App\Services\AI\Privacy;

use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may review whose AI conversations and AI action logs (Phase 8.1). Everyone sees their own.
 * Reviewing anyone else's needs the dedicated ai.conversations.view permission — not ai.manage,
 * not audit.view — and is limited to people in the reviewer's hierarchy unless they hold
 * hierarchy.view-all. The same rule backs the policies and the resource queries.
 */
class AiConversationVisibility
{
    public const string PERMISSION = 'ai.conversations.view';

    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function canReview(User $viewer): bool
    {
        return $viewer->can(self::PERMISSION);
    }

    public function canSee(User $viewer, ?int $ownerUserId): bool
    {
        if ($ownerUserId !== null && $ownerUserId === $viewer->id) {
            return true;
        }

        if (! $this->canReview($viewer) || $ownerUserId === null) {
            return false;
        }

        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($viewer);

        if ($visibleIds === null) {
            return true;
        }

        $ownerEmployeeId = User::query()->whereKey($ownerUserId)->value('employee_id');

        return $ownerEmployeeId !== null && $visibleIds->contains($ownerEmployeeId);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, User $viewer, string $userColumn = 'user_id'): Builder
    {
        if (! $this->canReview($viewer)) {
            return $query->where($userColumn, $viewer->id);
        }

        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($viewer);

        if ($visibleIds === null) {
            return $query;
        }

        return $query->where(fn (Builder $scoped) => $scoped
            ->where($userColumn, $viewer->id)
            ->orWhereIn($userColumn, User::query()->whereIn('employee_id', $visibleIds)->select('id')));
    }
}
