<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformRole;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use App\Services\Tenancy\TenantContext;
use Tests\Feature\IdentityAccess\IdentityWorld;

/**
 * SaaS-5 fixtures: the SaaS-2 identity world (acme and beta, each with its CHRO) plus platform
 * operators — two administrators (the two-person rule), a support operator, a compliance operator
 * and one operator holding every role. None of them is a member of any tenant.
 */
final class PlatformWorld
{
    public IdentityWorld $identity;

    public User $administrator;

    public User $secondAdministrator;

    public User $support;

    public User $compliance;

    public User $everything;

    public static function build(Tenant $acme): self
    {
        $world = new self;
        $world->identity = IdentityWorld::build($acme);
        $world->administrator = self::operator('admin@platform.test', PlatformRole::Administrator);
        $world->secondAdministrator = self::operator('admin2@platform.test', PlatformRole::Administrator);
        $world->support = self::operator('support@platform.test', PlatformRole::Support);
        $world->compliance = self::operator('compliance@platform.test', PlatformRole::Compliance);
        $world->everything = self::operator('all@platform.test', PlatformRole::Administrator, PlatformRole::Support, PlatformRole::Compliance);

        TenantMembership::query()->where('tenant_id', $acme->id)->where('user_id', $world->identity->adminA->id)->update(['is_owner' => true]);

        return $world;
    }

    public static function operator(string $email, PlatformRole ...$roles): User
    {
        $user = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => $email, 'name' => ucfirst(strtok($email, '@'))]));

        foreach ($roles as $role) {
            app(PlatformAccess::class)->grant($user, $role, 'Platform rota');
        }

        return $user;
    }
}
