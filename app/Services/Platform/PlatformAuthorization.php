<?php

namespace App\Services\Platform;

use App\Enums\PlatformCapability;
use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use DomainException;

/**
 * SaaS-5: the platform control plane's authorisation — explicit capabilities derived from the
 * operator's active platform roles (PlatformCapability::rolesGranting). A disabled identity holds
 * none. Every platform service checks here, server-side; panels only mirror it.
 */
class PlatformAuthorization
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function can(?User $user, PlatformCapability $capability): bool
    {
        if ($user === null || ! app(StaffAccessService::class)->identityPermits($user)) {
            return false;
        }

        $roles = $this->access->rolesOf($user);

        return collect($capability->rolesGranting())->contains(fn (PlatformRole $role): bool => $roles->contains($role));
    }

    /**
     * Whether $user operates the platform at all (any active platform role).
     */
    public function isOperator(?User $user): bool
    {
        return $user !== null && app(StaffAccessService::class)->identityPermits($user) && $this->access->rolesOf($user)->isNotEmpty();
    }

    public function authorize(?User $user, PlatformCapability $capability): User
    {
        if ($user === null || ! $this->can($user, $capability)) {
            throw new DomainException("This needs the platform capability {$capability->value}.");
        }

        return $user;
    }
}
