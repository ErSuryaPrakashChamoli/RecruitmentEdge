<?php

use App\Enums\AccessState;
use App\Enums\InvitationStatus;
use App\Enums\TenantStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Identity\InvitationRequiresSignIn;
use App\Services\Identity\InvitationUnavailable;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\StaffAccessService;
use App\Services\Identity\TenantInvitationService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concurrency\Race;

/*
 * SaaS-2: identity and membership changes under real two-transaction concurrency (MySQL 8.4,
 * REPEATABLE READ). The holder's transaction is open while a forked contender runs the conflicting
 * action; each fix is proven when the contender blocks on the lock and then decides on the
 * committed outcome. Run with: vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');
    Mail::fake();

    $this->home = TenantContext::current()->tenant();
    $this->other = Tenant::query()->firstOrCreate(['slug' => 'concurrency-identity'], [
        'name' => 'Concurrency Identity', 'status' => TenantStatus::Active, 'timezone' => 'Asia/Kolkata', 'locale' => 'en', 'currency' => 'INR', 'country' => 'IN',
    ]);
    // SaaS-3: like the home tenant, every capability without limits (the legacy plan).
    Race::pinPlan($this->other, 'legacy');

    foreach ([$this->home, $this->other] as $tenant) {
        TenantContext::current()->run($tenant, function (): void {
            if (! Role::query()->forCurrentTenant()->exists()) {
                (new RolePermissionSeeder)->run();
            }
        });
    }

    $this->inviter = User::factory()->create(['email' => 'inviter.'.Str::random(8).'@race.test'])->assignRole('chro');
    $this->otherInviter = TenantContext::current()->run($this->other, fn () => User::factory()->create(['email' => 'inviter.'.Str::random(8).'@race.test'])->assignRole('chro'));
    $this->invitations = app(TenantInvitationService::class);
    $this->email = 'person.'.Str::lower(Str::random(10)).'@race.test';
});

afterEach(function (): void {
    $users = User::query()->where('email', 'like', '%@race.test')->pluck('id');

    TenantInvitation::query()->withoutTenancy()->where('email', 'like', '%@race.test')->delete();
    DB::table('model_has_roles')->whereIn('model_id', $users)->where('model_type', (new User)->getMorphClass())->delete();
    DB::table('audit_logs')->whereIn('user_id', $users)->update(['user_id' => null]);
    TenantMembership::query()->whereIn('user_id', $users)->delete();
    User::query()->whereKey($users)->delete();
    Tenant::query()->whereKey($this->other->id)->update(['status' => TenantStatus::Active->value]);
});

function identityRaceInvite(object $test, ?Tenant $tenant = null, string $role = 'recruiter'): TenantInvitation
{
    $tenant ??= $test->home;
    $inviter = $tenant->is($test->home) ? $test->inviter : $test->otherInviter;

    return TenantContext::current()->run($tenant, fn () => app(TenantInvitationService::class)->invite(['email' => $test->email, 'roles' => [Role::byKeyOrFail($role)->id]], $inviter));
}

function identityRaceExistingPerson(string $email): User
{
    return TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => $email]));
}

test('two acceptances of one invitation create one membership; the second finds it used', function (): void {
    $invitation = identityRaceInvite($this);
    $person = identityRaceExistingPerson($this->email);

    $race = Race::run(
        holder: fn () => $this->invitations->accept($invitation, $person),
        contender: fn () => app(TenantInvitationService::class)->accept(TenantInvitation::query()->find($invitation->id), User::query()->find($person->id)),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(InvitationUnavailable::class)
        ->and(TenantMembership::query()->where('user_id', $person->id)->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('model_id', $person->id)->count())->toBe(1)
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Accepted);
});

test('a revocation that commits first wins: the acceptance waits, then finds the invitation revoked', function (): void {
    $invitation = identityRaceInvite($this);
    $person = identityRaceExistingPerson($this->email);

    $race = Race::run(
        holder: fn () => $this->invitations->revoke($invitation, $this->inviter, 'Sent by mistake'),
        contender: fn () => app(TenantInvitationService::class)->accept(TenantInvitation::query()->find($invitation->id), User::query()->find($person->id)),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(InvitationUnavailable::class)
        ->and(TenantMembership::query()->where('user_id', $person->id)->exists())->toBeFalse()
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Revoked);
});

test('an acceptance that commits first wins: the revocation waits, then finds nothing pending', function (): void {
    $invitation = identityRaceInvite($this);
    $person = identityRaceExistingPerson($this->email);

    $race = Race::run(
        holder: fn () => $this->invitations->accept($invitation, $person),
        contender: fn () => app(TenantInvitationService::class)->revoke(TenantInvitation::query()->find($invitation->id), User::query()->find($this->inviter->id), 'Too late'),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(DomainException::class)
        ->and($race['message'])->toContain('no longer pending')
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and(TenantMembership::query()->where('user_id', $person->id)->sole()->status)->toBe(AccessState::Active);
});

test('a tenant suspended while an invitation is being accepted activates no membership', function (): void {
    $invitation = identityRaceInvite($this, $this->other);
    $person = identityRaceExistingPerson($this->email);
    $other = $this->other;

    $race = Race::run(
        holder: function () use ($other): void {
            $tenant = Tenant::query()->whereKey($other->id)->lockForUpdate()->first();
            $tenant->update(['status' => TenantStatus::Suspended]);
        },
        contender: fn () => TenantContext::current()->run($other->id, fn () => app(TenantInvitationService::class)->accept(TenantInvitation::query()->find($invitation->id), User::query()->find($person->id))),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(InvitationUnavailable::class)
        ->and(TenantMembership::query()->where('user_id', $person->id)->exists())->toBeFalse();
});

test('two tenants\' invitations accepted at once by a new person create one identity; the other must sign in', function (): void {
    $home = identityRaceInvite($this);
    $other = identityRaceInvite($this, $this->other);
    $otherTenant = $this->other;

    $race = Race::run(
        holder: fn () => $this->invitations->acceptAsNewIdentity($home, 'Race Person', 'Tr1cky-Ledger-Horse'),
        contender: fn () => TenantContext::current()->run($otherTenant->id, fn () => app(TenantInvitationService::class)->acceptAsNewIdentity(TenantInvitation::query()->find($other->id), 'Race Person', 'Tr1cky-Ledger-Horse')),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(InvitationRequiresSignIn::class)
        ->and(User::query()->where('email', $this->email)->count())->toBe(1)
        ->and(TenantContext::current()->run($this->other, fn () => $other->fresh()->status))->toBe(InvitationStatus::Pending);
});

test('two invitations into one tenant accepted at once by the same person give one membership with the first one\'s roles', function (): void {
    $first = identityRaceInvite($this);
    $second = TenantInvitation::factory()->create(['email' => $this->email, 'role_ids' => [Role::byKeyOrFail('manager')->id], 'invited_by' => $this->inviter->id]);
    $person = identityRaceExistingPerson($this->email);

    $race = Race::run(
        holder: fn () => $this->invitations->accept($first, $person),
        contender: fn () => app(TenantInvitationService::class)->accept(TenantInvitation::query()->find($second->id), User::query()->find($person->id)),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and(TenantMembership::query()->where('user_id', $person->id)->count())->toBe(1)
        ->and($second->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and(User::query()->find($person->id)->getRoleNames()->all())->toBe(['recruiter']);
});

test('a role granted while the membership is being revoked waits, and never lands on the revoked membership', function (): void {
    $target = User::factory()->create(['email' => $this->email])->assignRole('recruiter');
    $manager = Role::byKeyOrFail('manager');
    $recruiter = Role::byKeyOrFail('recruiter');

    $race = Race::run(
        holder: fn () => app(StaffAccessService::class)->revoke($target, null, 'Left', 'employment'),
        contender: fn () => app(RoleAssignmentService::class)->syncUserRoles(User::query()->find($target->id), [$manager->id, $recruiter->id], User::query()->find($this->inviter->id)),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(DomainException::class)
        ->and($race['message'])->toContain('revoked')
        ->and(TenantMembership::query()->where('user_id', $target->id)->sole()->status)->toBe(AccessState::Revoked)
        ->and(DB::table('model_has_roles')->where('model_id', $target->id)->count())->toBe(0);
});
