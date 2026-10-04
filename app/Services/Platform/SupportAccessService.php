<?php

namespace App\Services\Platform;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\PlatformOperator;
use App\Models\SupportAccessGrant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-2 (foundation for SaaS-5): a tenant's explicit permission for one platform support operator
 * to help inside this tenant — granted by the tenant's own access administrators
 * (users.access.manage), time-bound (at most identity.support.max_minutes), revocable at any time,
 * with a reason, audited in the tenant's stream.
 *
 * A grant is only a record in SaaS-2: no code path lets anyone into a tenant because of it. The
 * SaaS-5 support console will require an active grant, open its own audited, read-only-by-default
 * session, and never use the customer's password or "log in as" them.
 */
class SupportAccessService
{
    public function grant(PlatformOperator $operator, int $minutes, string $reason, User $actor): SupportAccessGrant
    {
        if (! $actor->can('users.access.manage')) {
            throw new DomainException('Granting support access needs the users.access.manage permission.');
        }

        if (! $operator->isActive() || $operator->role !== PlatformRole::Support) {
            throw new DomainException('Support access can only be granted to an active platform support operator.');
        }

        $max = (int) config('identity.support.max_minutes', 480);

        if ($minutes < 1 || $minutes > $max) {
            throw new DomainException("Support access lasts between 1 and {$max} minutes.");
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to grant support access.');
        }

        $grant = SupportAccessGrant::query()->create([
            'platform_operator_id' => $operator->getKey(),
            'granted_by' => $actor->getKey(),
            'reason' => mb_substr($reason, 0, 255),
            'starts_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        AuditLog::record($grant, 'support_access_granted', null, ['platform_operator_id' => $operator->getKey(), 'minutes' => $minutes, 'reason' => $grant->reason, 'by_user_id' => $actor->getKey()]);
        Log::notice('identity.support_access_granted', ['grant_id' => $grant->getKey(), 'platform_operator_id' => $operator->getKey(), 'actor_id' => $actor->getKey()]);

        return $grant;
    }

    public function revoke(SupportAccessGrant $grant, User $actor, string $reason): void
    {
        if (! $actor->can('users.access.manage')) {
            throw new DomainException('Revoking support access needs the users.access.manage permission.');
        }

        $revoked = SupportAccessGrant::query()->whereKey($grant->getKey())->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'updated_at' => now()]);

        if ($revoked !== 1) {
            throw new DomainException('This support access was already revoked.');
        }

        AuditLog::record($grant, 'support_access_revoked', null, ['reason' => trim($reason), 'by_user_id' => $actor->getKey()]);
        Log::notice('identity.support_access_revoked', ['grant_id' => $grant->getKey(), 'actor_id' => $actor->getKey()]);
    }

    /**
     * The operator's grant in the current tenant that is active right now, if any.
     */
    public function activeGrant(PlatformOperator $operator): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()
            ->where('platform_operator_id', $operator->getKey())
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }
}
