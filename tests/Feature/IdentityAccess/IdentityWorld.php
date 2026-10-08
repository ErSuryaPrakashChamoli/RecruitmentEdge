<?php

namespace Tests\Feature\IdentityAccess;

use App\Enums\AccessState;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;

/**
 * SaaS-2 identity fixtures: two tenants (the test's own "acme" and "beta") with the global
 * identities of the SaaS-2 authorisation matrix (prompt §23):
 *
 * - personA: acme = Active manager, beta = Active recruiter (an employee in each tenant)
 * - personB: acme = Active recruiter only
 * - personC: acme = Suspended (recruiter role dormant)
 * - personD: no membership anywhere
 * - personE: Active recruiter in both tenants, no employee record anywhere
 * - adminA / adminB: each tenant's CHRO (users.manage, users.access.manage, ...)
 *
 * Memberships are created the way an accepted invitation leaves them (Active, the tenant's roles
 * assigned inside that tenant).
 */
final class IdentityWorld
{
    public Tenant $acme;

    public Tenant $beta;

    public User $personA;

    public User $personB;

    public User $personC;

    public User $personD;

    public User $personE;

    public User $adminA;

    public User $adminB;

    public Employee $personAInAcme;

    public Employee $personAInBeta;

    public static function build(Tenant $acme): self
    {
        $world = new self;
        $world->acme = $acme;
        $world->beta = Tenant::factory()->create(['slug' => 'beta', 'name' => 'Beta Staffing']);

        TenantContext::current()->run($world->acme, fn () => (new RolePermissionSeeder)->run());
        TenantContext::current()->run($world->beta, fn () => (new RolePermissionSeeder)->run());

        $world->adminA = TenantContext::current()->run($world->acme, fn () => User::factory()->create(['name' => 'Acme Chief', 'email' => 'chief@acme.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro'));
        $world->adminB = TenantContext::current()->run($world->beta, fn () => User::factory()->create(['name' => 'Beta Chief', 'email' => 'chief@beta.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro'));

        $world->personAInAcme = TenantContext::current()->run($world->acme, fn () => Employee::factory()->create(['first_name' => 'Asha', 'last_name' => 'Acme']));
        $world->personAInBeta = TenantContext::current()->run($world->beta, fn () => Employee::factory()->create(['first_name' => 'Asha', 'last_name' => 'Beta']));

        $world->personA = TenantContext::current()->run($world->acme, fn () => User::factory()->create(['name' => 'Asha Two-Tenants', 'email' => 'asha@example.test', 'employee_id' => $world->personAInAcme->id])->assignRole('manager'));
        self::join($world->personA, $world->beta, 'recruiter', $world->personAInBeta);

        $world->personB = TenantContext::current()->run($world->acme, fn () => User::factory()->create(['name' => 'Bala Acme', 'email' => 'bala@example.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('recruiter'));

        $world->personC = TenantContext::current()->run($world->acme, fn () => User::factory()->create(['name' => 'Chen Suspended', 'email' => 'chen@example.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('recruiter'));
        TenantMembership::query()->where('tenant_id', $world->acme->id)->where('user_id', $world->personC->id)->update(['status' => AccessState::Suspended->value]);

        $world->personD = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['name' => 'Dev Nowhere', 'email' => 'dev@example.test']));

        $world->personE = TenantContext::current()->run($world->acme, fn () => User::factory()->create(['name' => 'Esi Everywhere', 'email' => 'esi@example.test'])->assignRole('recruiter'));
        self::join($world->personE, $world->beta, 'recruiter');

        return $world;
    }

    /**
     * An Active membership of $tenant with $role there (and the tenant's employee record).
     */
    public static function join(User $user, Tenant $tenant, string $role, ?Employee $employee = null): void
    {
        TenantMembership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_id' => $employee?->id, 'status' => AccessState::Active, 'joined_at' => now()]);
        TenantContext::current()->run($tenant, fn () => $user->assignRole($role));
    }
}
