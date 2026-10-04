<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-2 (prompt §36): the membership checks every panel request makes — sign-in gate, tenant
 * check, switcher, default tenant — cost a few queries once per request, a small constant more per
 * tenant the person belongs to, and nothing per row in a list.
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
});

function identityPerformanceQueries(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('a request\'s membership checks are decided once: the switcher, the tenant check and the default reuse them', function (): void {
    $person = $this->world->personA->fresh();
    $panel = Filament::getPanel('admin');

    $first = identityPerformanceQueries(fn () => $person->canAccessPanel($panel));
    $again = identityPerformanceQueries(function () use ($person, $panel): void {
        $person->canAccessPanel($panel);
        $person->canAccessTenant($this->world->acme);
        $person->canAccessTenant($this->world->beta);
        $person->getTenants($panel);
        $person->getDefaultTenant($panel);
    });

    expect($first)->toBeLessThanOrEqual(8)
        ->and($again)->toBe(0);
});

test('each additional tenant costs a small constant number of queries', function (): void {
    $person = $this->world->personE;
    $panel = Filament::getPanel('admin');
    $two = identityPerformanceQueries(fn () => $person->fresh()->canAccessPanel($panel));

    foreach (['gamma', 'delta', 'epsilon'] as $slug) {
        $tenant = Tenant::factory()->create(['slug' => $slug]);
        TenantContext::current()->run($tenant, fn () => (new RolePermissionSeeder)->run());
        IdentityWorld::join($person, $tenant, 'recruiter', TenantContext::current()->run($tenant, fn () => Employee::factory()->create()));
    }

    StaffAccessService::invalidateDecisions();
    $five = identityPerformanceQueries(fn () => $person->fresh()->canAccessPanel($panel));

    expect($five - $two)->toBeLessThanOrEqual(3 * 2);
});

test('the Users list costs the same number of queries for 3 or 15 members, shared identities included', function (): void {
    $this->actingAs($this->world->adminA);
    $render = fn (): int => identityPerformanceQueries(fn () => Livewire::test(ListUsers::class)->assertOk());

    $small = $render();

    foreach (range(1, 12) as $i) {
        $member = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');

        if ($i % 3 === 0) {
            IdentityWorld::join($member, $this->world->beta, 'recruiter');
        }
    }

    StaffAccessService::invalidateDecisions();
    $large = $render();

    expect($large - $small)->toBeLessThanOrEqual(4);
});
