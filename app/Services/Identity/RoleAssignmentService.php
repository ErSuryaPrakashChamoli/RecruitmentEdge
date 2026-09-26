<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Events\UserRoleChanged;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Phase 8.4: the only path for granting or removing roles and for changing what a role grants.
 * Every rule against delegated-administration escalation lives here (with AuthorityGuard):
 *
 * - Nobody changes their own roles, and nobody edits a role they hold.
 * - You can only grant a role, or give a role permissions, that you hold yourself.
 * - A protected role (CHRO, identified by its immutable key) is granted or removed only by a holder
 *   of it; its permission set cannot be edited here; it cannot be deleted.
 * - Removing CHRO never leaves the organisation without an effective CHRO.
 *
 * Every change is audited with the old and new role / permission names and announced after commit.
 */
class RoleAssignmentService
{
    public function __construct(private readonly AuthorityGuard $authority) {}

    /**
     * Replaces $target's roles with $roleIds.
     *
     * @param  array<int, int|string>  $roleIds
     */
    public function syncUserRoles(User $target, array $roleIds, User $actor, bool $newUser = false): User
    {
        if (! $actor->can('users.manage')) {
            throw new DomainException('Assigning roles needs the users.manage permission.');
        }

        $this->authority->assertNotSelf($actor, $target, 'You cannot change your own roles.');

        if (! $newUser) {
            $this->authority->assertInScope($actor, $target);
        }

        if (($target->access_status ?? AccessState::Active) === AccessState::Revoked) {
            throw new DomainException('This login is revoked. Restore access first; it then starts with the base role.');
        }

        $wanted = Role::query()->whereKey(array_map('intval', $roleIds))->get();
        $current = $target->roles()->get();
        $added = $wanted->diff($current);
        $removed = $current->diff($wanted);

        if ($added->isEmpty() && $removed->isEmpty()) {
            return $target;
        }

        foreach ($added as $role) {
            $this->assertCanGrant($actor, $role);
        }

        foreach ($removed as $role) {
            if ($role->is_protected && ! $actor->hasRole($role)) {
                $this->refuseProtected($role, $actor, 'remove');
            }
        }

        return $this->authority->protecting('role_removed', $actor, fn (): User => DB::transaction(function () use ($target, $wanted, $current, $removed, $actor): User {
            if ($removed->contains(fn (Role $role) => $role->key === config('identity.chro_role'))) {
                $this->authority->assertEffectiveChroRemainsWithout($target);
            }

            $target->syncRoles($wanted);

            AuditLog::record($target, 'roles_changed', ['roles' => $current->pluck('name')->sort()->values()->all()], ['roles' => $wanted->pluck('name')->sort()->values()->all(), 'by_user_id' => $actor->id]);
            Log::info('identity.roles_changed', ['user_id' => $target->id, 'actor_id' => $actor->id, 'added' => $wanted->diff($current)->pluck('key')->all(), 'removed' => $removed->pluck('key')->all()]);

            StaffAccessService::invalidateDecisions();
            UserRoleChanged::dispatch($target->id, $actor->id);

            return $target;
        }));
    }

    /**
     * Gives a newly provisioned login the base role (identity.base_role) and nothing else — the
     * provisioning path of candidate conversion and rehire, which needs employees.convert, never
     * users.manage. Privileged roles are only ever granted through syncUserRoles().
     */
    public function grantBaseRole(User $user, string $source, ?User $actor): void
    {
        $base = Role::byKeyOrFail((string) config('identity.base_role'));

        if ($user->roles()->whereKey($base->getKey())->exists() && $user->roles()->count() === 1) {
            return;
        }

        $before = $user->roles()->pluck('name')->sort()->values()->all();
        $user->syncRoles([$base]);

        AuditLog::record($user, 'roles_changed', ['roles' => $before], ['roles' => [$base->name], 'source' => $source, 'by_user_id' => $actor?->id]);
        StaffAccessService::invalidateDecisions();
        UserRoleChanged::dispatch($user->id, $actor?->id);
    }

    /**
     * @param  array<int, int|string>  $permissionIds
     */
    public function createRole(string $name, array $permissionIds, User $actor): Role
    {
        $this->assertCanManageRoles($actor);
        $permissions = $this->permissions($permissionIds);
        $this->assertHoldsPermissions($actor, $permissions, 'grant a role');

        return DB::transaction(function () use ($name, $permissions, $actor): Role {
            $role = Role::query()->create(['name' => trim($name), 'guard_name' => 'web']);
            $role->syncPermissions($permissions);

            AuditLog::record($role, 'created', null, ['name' => $role->name, 'permissions' => $permissions->pluck('name')->sort()->values()->all()]);
            Log::info('identity.role_created', ['role_id' => $role->id, 'actor_id' => $actor->id]);

            return $role->refresh();
        });
    }

    /**
     * Renames a role and/or replaces its permission set. A protected role may be renamed (it stays
     * protected — protection follows the key) but its permissions cannot be edited.
     *
     * @param  array<int, int|string>  $permissionIds
     */
    public function updateRole(Role $role, string $name, array $permissionIds, User $actor): Role
    {
        $this->assertCanManageRoles($actor);

        if ($actor->hasRole($role)) {
            if ($role->is_protected) {
                $this->refuseProtected($role, $actor, 'edit_own', 'You cannot edit a role you hold.');
            }

            throw new DomainException('You cannot edit a role you hold.');
        }

        $oldName = $role->name;
        $oldPermissions = $role->permissions()->get();
        $permissions = $this->permissions($permissionIds);
        $permissionsChanged = $permissions->pluck('id')->sort()->values()->all() !== $oldPermissions->pluck('id')->sort()->values()->all();

        if ($role->is_protected && $permissionsChanged) {
            $this->refuseProtected($role, $actor, 'edit_permissions', "The {$role->name} role is protected; its permissions cannot be changed here.");
        }

        $this->assertHoldsPermissions($actor, $permissions->diff($oldPermissions), 'give a role');

        return DB::transaction(function () use ($role, $name, $permissions, $oldName, $oldPermissions, $permissionsChanged, $actor): Role {
            $role->forceFill(['name' => trim($name)])->save();

            if ($permissionsChanged) {
                $role->syncPermissions($permissions);
            }

            $old = [];
            $new = [];

            if ($oldName !== $role->name) {
                [$old['name'], $new['name']] = [$oldName, $role->name];
            }

            if ($permissionsChanged) {
                [$old['permissions'], $new['permissions']] = [$oldPermissions->pluck('name')->sort()->values()->all(), $permissions->pluck('name')->sort()->values()->all()];
            }

            if ($new !== []) {
                AuditLog::record($role, 'permissions_updated', $old, $new);
                Log::info('identity.role_updated', ['role_id' => $role->id, 'actor_id' => $actor->id]);
                StaffAccessService::invalidateDecisions();
            }

            return $role;
        });
    }

    public function deleteRole(Role $role, User $actor): void
    {
        $this->assertCanManageRoles($actor);

        if ($role->is_protected) {
            $this->refuseProtected($role, $actor, 'delete', "The {$role->name} role is protected and cannot be deleted.");
        }

        if ($actor->hasRole($role)) {
            throw new DomainException('You cannot delete a role you hold.');
        }

        DB::transaction(function () use ($role, $actor): void {
            AuditLog::record($role, 'deleted', [
                'name' => $role->name,
                'key' => $role->key,
                'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
                'holders' => $role->users()->count(),
            ], null);

            $role->delete();
            Log::info('identity.role_deleted', ['role_id' => $role->id, 'actor_id' => $actor->id]);
            StaffAccessService::invalidateDecisions();
        });
    }

    /**
     * The roles $actor may grant: every permission of the role held by $actor, and protected
     * roles only by their holders.
     *
     * @return Collection<int, Role>
     */
    public function grantableRoles(User $actor): Collection
    {
        return Role::query()->with('permissions')->orderBy('name')->get()->filter(fn (Role $role) => $this->canGrant($actor, $role))->values();
    }

    public function canGrant(User $actor, Role $role): bool
    {
        if ($role->is_protected && ! $actor->hasRole($role)) {
            return false;
        }

        $held = $actor->getAllPermissions()->pluck('name');

        return $role->permissions->pluck('name')->diff($held)->isEmpty();
    }

    private function assertCanGrant(User $actor, Role $role): void
    {
        if ($role->is_protected && ! $actor->hasRole($role)) {
            $this->refuseProtected($role, $actor, 'grant');
        }

        if (! $this->canGrant($actor, $role->loadMissing('permissions'))) {
            throw new DomainException("You cannot grant the {$role->name} role: it carries permissions you do not hold.");
        }
    }

    private function assertCanManageRoles(User $actor): void
    {
        if (! $actor->can('roles.manage')) {
            throw new DomainException('Managing roles needs the roles.manage permission.');
        }
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     */
    private function assertHoldsPermissions(User $actor, Collection $permissions, string $what): void
    {
        $missing = $permissions->pluck('name')->diff($actor->getAllPermissions()->pluck('name'));

        if ($missing->isNotEmpty()) {
            throw new DomainException("You cannot {$what} permissions you do not hold yourself ({$missing->sort()->implode(', ')}).");
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Permission>
     */
    private function permissions(array $ids): Collection
    {
        /** @var class-string<Permission> $model */
        $model = config('permission.models.permission');

        return $model::query()->whereKey(array_map('intval', $ids))->get();
    }

    private function refuseProtected(Role $role, User $actor, string $attempt, ?string $message = null): never
    {
        AuditLog::record($role, 'protected_role_refused', null, ['attempt' => $attempt, 'role_key' => $role->key, 'by_user_id' => $actor->id]);
        Log::warning('identity.protected_role_refused', ['role_id' => $role->id, 'attempt' => $attempt, 'actor_id' => $actor->id]);

        throw new DomainException($message ?? "Only a holder of the {$role->name} role can {$attempt} it.");
    }
}
