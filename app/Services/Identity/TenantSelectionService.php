<?php

namespace App\Services\Identity;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-2: which tenant a signed-in person is working in, and their default.
 *
 * The tenant of a panel request is ALWAYS the one in its URL, checked against the person's
 * membership on every request (User::canAccessTenant, SetTenantContextFromPanel). The session only
 * remembers the last tenant entered — to audit a switch once and to give tenant-less pages (the
 * profile) a tenant — and that remembered tenant is re-checked before every use. Never a tenant
 * named by the request or chosen for the person.
 */
class TenantSelectionService
{
    public const string SESSION_KEY = 'identity.tenant_id';

    /**
     * Called for every panel request once the URL's tenant has passed the membership check. The
     * first request in a tenant (sign-in, a switch, another tab) is audited in that tenant's
     * stream; the platform log keeps where the person came from (tenants never see each other).
     */
    public function entered(User $user, Tenant $tenant, ?Session $session): void
    {
        $previous = $session?->get(self::SESSION_KEY);

        if ($previous !== null && (int) $previous === (int) $tenant->getKey()) {
            return;
        }

        $session?->put(self::SESSION_KEY, (int) $tenant->getKey());

        TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->update(['last_selected_at' => now()]);

        AuditLog::record($user, $previous === null ? 'tenant_selected' : 'tenant_switched', null, ['tenant' => $tenant->slug]);
        Log::info('identity.tenant_'.($previous === null ? 'selected' : 'switched'), ['user_id' => $user->getKey(), 'from_tenant_id' => $previous, 'to_tenant_id' => $tenant->getKey()]);
    }

    /**
     * The tenant a tenant-less page works in: the tenant last entered in this session, else the
     * person's default — each only while the person may still enter it. Otherwise none.
     */
    public function workingTenant(User $user, ?Session $session): ?Tenant
    {
        $remembered = $session?->get(self::SESSION_KEY);
        $tenants = $user->accessibleTenants();

        if ($remembered !== null && ($tenant = $tenants->first(fn (Tenant $tenant): bool => (int) $tenant->getKey() === (int) $remembered)) !== null) {
            return $tenant;
        }

        $default = $user->getDefaultTenant(Filament::getPanel('admin'));

        return $default instanceof Tenant ? $default : null;
    }

    /**
     * Makes $tenant the person's default. Only their own, currently accessible membership.
     */
    public function makeDefault(User $user, Tenant $tenant): void
    {
        if (! $user->canAccessTenant($tenant)) {
            throw new DomainException('You can only make an organisation you can open your default.');
        }

        DB::transaction(function () use ($user, $tenant): void {
            TenantMembership::query()->where('user_id', $user->getKey())->where('tenant_id', '!=', $tenant->getKey())->where('is_default', true)->update(['is_default' => false]);
            TenantMembership::query()->where('user_id', $user->getKey())->where('tenant_id', $tenant->getKey())->update(['is_default' => true]);
        });

        StaffAccessService::invalidateDecisions();
        $user->unsetRelation('memberships');

        AuditLog::record($user, 'default_tenant_set', null, ['tenant' => $tenant->slug]);
        Log::info('identity.default_tenant_set', ['user_id' => $user->getKey(), 'tenant_id' => $tenant->getKey()]);
    }
}
