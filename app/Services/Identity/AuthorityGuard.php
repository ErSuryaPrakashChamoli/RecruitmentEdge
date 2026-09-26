<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\HierarchyService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: the delegated-administration rules shared by every identity service. An
 * administrator may act on another person's access only with the permission for it, never on
 * themselves, and only within their own hierarchy (a user without an employee record is reachable
 * only by hierarchy.view-all holders).
 */
class AuthorityGuard
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function assertCanManageAccess(User $actor, User $target): void
    {
        if (! $actor->can('users.access.manage')) {
            throw new DomainException('Managing access needs the users.access.manage permission.');
        }

        $this->assertNotSelf($actor, $target, 'You cannot change your own access.');
        $this->assertInScope($actor, $target);
    }

    public function assertNotSelf(User $actor, User $target, string $message): void
    {
        if ($actor->is($target)) {
            throw new DomainException($message);
        }
    }

    /**
     * The target's employee record must be inside the actor's hierarchy. A login with no employee
     * record is reachable only with hierarchy.view-all.
     */
    public function assertInScope(User $actor, User $target): void
    {
        if (! $this->inScope($actor, $target)) {
            throw new DomainException('This person is outside your hierarchy.');
        }
    }

    /**
     * Last-CHRO protection: refuses a change that would leave the organisation without an
     * effective CHRO — a login holding the CHRO role (by key) whose access is Active and permitted.
     * $user is the person losing CHRO authority; nothing is refused when they are not a CHRO.
     */
    public function assertEffectiveChroRemainsWithout(User $user): void
    {
        if (! $this->isEffectiveChro($user)) {
            return;
        }

        if ($this->effectiveChroIds()->reject(fn (int $id) => $id === (int) $user->getKey())->isEmpty()) {
            throw new LastChroProtectedException('This is the last active CHRO. Assign the CHRO role to another person first.');
        }
    }

    /**
     * Audits a refused protected-authority change. Called after the caller's transaction rolled
     * back, so the record of the attempt survives.
     */
    public function recordProtection(User $user, string $attempt, ?User $actor = null): void
    {
        AuditLog::record($user, 'last_chro_protected', null, ['attempt' => $attempt, 'by_user_id' => $actor?->getKey()]);
        Log::warning('identity.last_chro_protected', ['user_id' => $user->getKey(), 'attempt' => $attempt, 'actor_id' => $actor?->getKey()]);
    }

    public function isEffectiveChro(User $user): bool
    {
        return $this->effectiveChroIds()->contains((int) $user->getKey());
    }

    /**
     * @return Collection<int, int>
     */
    public function effectiveChroIds(): Collection
    {
        $chro = Role::byKey((string) config('identity.chro_role'));

        if ($chro === null) {
            return collect();
        }

        $access = app(StaffAccessService::class);

        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereKey($chro->getKey()))
            ->where('access_status', AccessState::Active->value)
            ->get()
            ->filter(fn (User $candidate) => $access->permits($candidate))
            ->map(fn (User $candidate) => (int) $candidate->getKey())
            ->values();
    }

    public function inScope(User $actor, User $target): bool
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($actor);

        if ($visible === null) {
            return true;
        }

        return $target->employee_id !== null && $visible->contains($target->employee_id);
    }
}
