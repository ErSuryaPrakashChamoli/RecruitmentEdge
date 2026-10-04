<?php

namespace App\Policies;

use App\Models\TenantInvitation;
use App\Models\User;

/**
 * SaaS-2: invitations are this tenant's own (TenantScope), managed with users.manage. They are
 * created, resent and revoked only through TenantInvitationService — never edited or deleted as
 * records (the history of who was invited stays).
 */
class TenantInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, TenantInvitation $tenantInvitation): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TenantInvitation $tenantInvitation): bool
    {
        return $user->can('users.manage');
    }

    public function delete(User $user, TenantInvitation $tenantInvitation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, TenantInvitation $tenantInvitation): bool
    {
        return false;
    }

    public function forceDelete(User $user, TenantInvitation $tenantInvitation): bool
    {
        return false;
    }
}
