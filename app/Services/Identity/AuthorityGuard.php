<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\HierarchyService;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        // Phase 8.9 (P89-SEC-004): decided under the CHRO role's row lock, counting with a locking
        // read — two concurrent suspensions, separations or role removals are decided one after the
        // other on committed state, so they can never leave the organisation without a CHRO.
        $this->serialiseAuthorityChanges();
        $effective = $this->effectiveChroIds(locking: true);

        if (! $effective->contains((int) $user->getKey())) {
            return;
        }

        if ($effective->reject(fn (int $id) => $id === (int) $user->getKey())->isEmpty()) {
            throw new LastChroProtectedException('This is the last active CHRO. Assign the CHRO role to another person first.', $user);
        }
    }

    private static int $depth = 0;

    /**
     * Runs an identity operation. If it would leave the organisation without an effective CHRO,
     * the outermost operation audits the refused attempt once everything has rolled back (nested
     * operations — employment calling access — let it bubble up).
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function protecting(string $attempt, ?User $actor, Closure $operation): mixed
    {
        self::$depth++;

        try {
            // Phase 8.9 (P89-SEC-004): the outermost protected operation runs in one transaction whose
            // first statement takes the CHRO role's row lock, before any user or employee lock — every
            // authority-removing change queues there, and decides on what the previous one committed.
            return self::$depth === 1
                ? DB::transaction(function () use ($operation): mixed {
                    $this->serialiseAuthorityChanges();

                    return $operation();
                })
                : $operation();
        } catch (LastChroProtectedException $e) {
            if (self::$depth === 1 && $e->user !== null) {
                $this->recordProtection($e->user, $attempt, $actor);
            }

            throw $e;
        } finally {
            self::$depth--;
        }
    }

    /**
     * Audits a refused protected-authority change.
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
     * The serialisation point of every change that can remove CHRO authority: the CHRO role's row,
     * locked for the rest of the transaction. Re-entrant within one transaction.
     */
    public function serialiseAuthorityChanges(): void
    {
        if (($chro = Role::byKey((string) config('identity.chro_role'))) !== null) {
            Role::query()->whereKey($chro->getKey())->lockForUpdate()->first();
        }
    }

    /**
     * @param  bool  $locking  read the role assignments and access states with a locking read (the
     *                         latest committed rows, whatever snapshot the transaction holds)
     * @return Collection<int, int>
     */
    public function effectiveChroIds(bool $locking = false): Collection
    {
        $chro = Role::byKey((string) config('identity.chro_role'));

        if ($chro === null) {
            return collect();
        }

        $access = app(StaffAccessService::class);

        return User::query()
            ->select('users.*')
            ->join('model_has_roles', fn ($join) => $join->on('model_has_roles.model_id', '=', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                ->where('model_has_roles.role_id', $chro->getKey()))
            ->where('users.access_status', AccessState::Active->value)
            ->when($locking, fn (Builder $query) => $query->sharedLock())
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
