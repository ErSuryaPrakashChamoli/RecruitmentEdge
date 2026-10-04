<?php

use App\Enums\AccessState;
use App\Enums\InvitationStatus;
use App\Enums\TenantStatus;
use App\Filament\Resources\TenantInvitations\Pages\ListTenantInvitations;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Http\Controllers\Identity\TenantInvitationController;
use App\Mail\TenantInvitationMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Identity\InvitationUnavailable;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\StaffAccessService;
use App\Services\Identity\TenantInvitationService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\IdentityAccess\IdentityWorld;

use function Pest\Laravel\actingAs;

/*
 * SaaS-2: invitations are the only way into a tenant. The link proves the address; acceptance
 * attaches exactly the invited identity (or creates it once), inside the invitation's own tenant,
 * with roles the inviter could grant — and never reveals who already exists on the platform.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->world = IdentityWorld::build($this->tenant);
    $this->invitations = app(TenantInvitationService::class);
});

function membershipStatusOf(User $user): ?AccessState
{
    return TenantMembership::query()->where('tenant_id', TenantContext::current()->requireId())->where('user_id', $user->id)->first()?->status;
}

function invitationLastLink(): string
{
    return Mail::sent(TenantInvitationMail::class)->last()->url;
}

function invitationInviteToBeta(IdentityWorld $world, string $email, string $role = 'recruiter'): TenantInvitation
{
    return TenantContext::current()->run($world->beta, fn () => app(TenantInvitationService::class)->invite(['email' => $email, 'roles' => [Role::byKeyOrFail($role)->id]], $world->adminB));
}

test('an invitation stores only the hash of its single-use token, emails the link and is audited without it', function (): void {
    $invitation = $this->invitations->invite(['email' => '  New.Person@Example.TEST ', 'name' => 'New Person', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA);
    $token = (string) str(invitationLastLink())->afterLast('/');

    expect($invitation->email)->toBe('new.person@example.test')
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and(strlen($token))->toBe(64)
        ->and($invitation->fresh()->token_hash)->toBe(hash('sha256', $token))
        ->and(DB::table('tenant_invitations')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(str_contains(json_encode(AuditLog::query()->get()->toArray()), $token))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'invitation_created')->sole()->getAttribute('changes'))->toMatchArray(['email' => 'new.person@example.test', 'roles' => ['recruiter'], 'source' => 'administrator']);

    // The link names no tenant, and is built on the application's own host (P810-SEC-001).
    Mail::assertSent(TenantInvitationMail::class, fn (TenantInvitationMail $mail) => $mail->hasTo('new.person@example.test') && $mail->tenantName === 'Acme Hiring' && ! str_contains($mail->url, 'acme') && str_starts_with($mail->url, rtrim((string) config('app.url'), '/').'/invitations/'));
});

test('inviting needs users.manage, roles the inviter may grant, and this tenant\'s roles only', function (): void {
    $betaChro = TenantContext::current()->run($this->world->beta, fn () => Role::byKeyOrFail('chro'));
    $managerOnly = User::factory()->create()->assignRole('manager');

    expect(fn () => $this->invitations->invite(['email' => 'x@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->personB))->toThrow(DomainException::class, 'users.manage')
        ->and(fn () => $this->invitations->invite(['email' => 'x@example.test', 'roles' => [$betaChro->id]], $this->world->adminA))->toThrow(DomainException::class, 'does not exist in this organisation')
        ->and(fn () => $this->invitations->invite(['email' => 'x@example.test', 'roles' => []], $this->world->adminA))->toThrow(DomainException::class, 'at least one role')
        ->and(fn () => $this->invitations->invite(['email' => 'not-an-email', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA))->toThrow(DomainException::class, 'valid email')
        ->and($managerOnly->can('users.manage'))->toBeFalse()
        ->and(TenantInvitation::query()->count())->toBe(0);
});

test('inviting an address that already has an identity elsewhere looks exactly like inviting a new one (S1-01)', function (): void {
    actingAs($this->world->adminA);
    $recruiter = Role::byKeyOrFail('recruiter')->id;

    $shown = [];

    foreach (['brand.new@example.test', 'chief@beta.test'] as $email) {
        Livewire::test(ListUsers::class)
            ->callAction('inviteMember', ['email' => $email, 'roles' => [(string) $recruiter]])
            ->assertHasNoActionErrors()
            ->assertNotified('Invitation sent');

        $shown[] = TenantInvitation::query()->where('email', $email)->sole()->only(['status', 'role_ids', 'source']);
    }

    expect($shown[0])->toBe($shown[1])
        ->and(Mail::sent(TenantInvitationMail::class)->count())->toBe(2)
        ->and(User::query()->where('email', 'brand.new@example.test')->exists())->toBeFalse();
});

test('a person who is already a member of this tenant is not invited again; a revoked member may be', function (): void {
    expect(fn () => $this->invitations->invite(['email' => 'bala@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA))->toThrow(DomainException::class, 'already uses this email');

    app(StaffAccessService::class)->revoke($this->world->personB, $this->world->adminA, 'Left');

    expect($this->invitations->invite(['email' => 'bala@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA)->status)->toBe(InvitationStatus::Pending);
});

test('the link leaves the URL at once; the page names the tenant and masks the address', function (): void {
    invitationInviteToBeta($this->world, 'brand.new@example.test');

    $this->get(invitationLastLink())->assertRedirect(route('invitations.show'));

    expect(session(TenantInvitationController::SESSION_KEY))->toHaveLength(64)
        ->and(session(TenantInvitationController::SESSION_KEY))->not->toBe((string) str(invitationLastLink())->afterLast('/'));

    $this->get(route('invitations.show'))->assertOk()->assertSee('Join Beta Staffing')->assertSee('b••••@example.test')->assertDontSee('brand.new@example.test');
});

test('a new person creates their identity once, joins the invitation\'s tenant with its roles, and signs in there', function (): void {
    invitationInviteToBeta($this->world, 'brand.new@example.test');

    $this->get(invitationLastLink());
    $this->post(route('invitations.register'), ['name' => 'Brand New', 'password' => 'Tr1cky-Ledger-Horse', 'password_confirmation' => 'Tr1cky-Ledger-Horse'])
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $user = User::query()->where('email', 'brand.new@example.test')->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(TenantMembership::query()->where('user_id', $user->id)->pluck('tenant_id')->all())->toBe([$this->world->beta->id])
        ->and(TenantContext::current()->run($this->world->beta, fn () => $user->fresh()->getRoleNames()->all()))->toBe(['recruiter'])
        ->and($user->fresh()->getRoleNames()->all())->toBe([])
        ->and($user->fresh()->getDefaultTenant(Filament::getPanel('admin'))?->is($this->world->beta))->toBeTrue()
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'invitation_accepted')->exists()))->toBeTrue();

    $this->actingAs($user->fresh())->get('/admin/beta')->assertOk();
    $this->get('/admin/acme')->assertNotFound();
});

test('an existing identity is never duplicated: it signs in and accepts, keeping one identity in two tenants', function (): void {
    invitationInviteToBeta($this->world, 'BALA@example.test ');
    $this->get(invitationLastLink());

    $this->post(route('invitations.register'), ['name' => 'Someone Else', 'password' => 'Tr1cky-Ledger-Horse', 'password_confirmation' => 'Tr1cky-Ledger-Horse'])
        ->assertRedirect(route('invitations.show'))
        ->assertSessionHasErrors('invitation');

    expect(User::query()->whereEmailIs('bala@example.test')->count())->toBe(1);

    $this->post(route('invitations.accept'))->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    // Working in Acme when accepting: the tenant joined is still the invitation's own (Beta).
    $this->actingAs($this->world->personB->fresh())->get('/admin/acme')->assertOk();
    $this->post(route('invitations.accept'))->assertRedirect(Filament::getPanel('admin')->getUrl($this->world->beta));

    expect(User::query()->whereEmailIs('bala@example.test')->count())->toBe(1)
        ->and(TenantMembership::query()->where('user_id', $this->world->personB->id)->orderBy('tenant_id')->pluck('tenant_id')->all())->toBe([$this->world->acme->id, $this->world->beta->id])
        ->and(TenantContext::current()->run($this->world->beta, fn () => $this->world->personB->fresh()->getRoleNames()->all()))->toBe(['recruiter'])
        ->and($this->world->personB->fresh()->getRoleNames()->all())->toBe(['recruiter']);
});

test('a different signed-in identity can never accept someone else\'s invitation', function (): void {
    $invitation = invitationInviteToBeta($this->world, 'bala@example.test');
    $this->get(invitationLastLink());

    $this->actingAs($this->world->personE)->get(route('invitations.show'))->assertSee('signed in with a different account');
    $this->post(route('invitations.accept'))->assertRedirect(route('invitations.show'))->assertSessionHasErrors('invitation');

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and(TenantMembership::query()->where('tenant_id', $this->world->beta->id)->where('user_id', $this->world->personB->id)->exists())->toBeFalse()
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'invitation_refused')->sole()->getAttribute('changes')))->toMatchArray(['reason' => 'wrong_identity']);
});

test('expired, revoked, used and unknown links all get the same answer, and nothing is joined', function (string $state): void {
    $invitation = invitationInviteToBeta($this->world, 'brand.new@example.test');
    $link = invitationLastLink();

    match ($state) {
        'expired' => $this->travel(73)->hours(),
        'revoked' => TenantContext::current()->run($this->world->beta, fn () => $this->invitations->revoke($invitation, $this->world->adminB, 'Sent by mistake')),
        'used' => TenantContext::current()->run($this->world->beta, fn () => $this->invitations->acceptAsNewIdentity($invitation, 'Brand New', 'Tr1cky-Ledger-Horse')),
        'unknown' => $link = route('invitations.open', ['token' => str_repeat('a', 64)]),
    };

    $this->get($link)->assertNotFound()->assertSee('This invitation can\'t be used');
    $this->get(route('invitations.show'))->assertNotFound();
    $this->post(route('invitations.register'), ['name' => 'X', 'password' => 'Tr1cky-Ledger-Horse', 'password_confirmation' => 'Tr1cky-Ledger-Horse'])->assertNotFound();

    expect(User::query()->where('email', 'brand.new@example.test')->count())->toBe($state === 'used' ? 1 : 0);
})->with(['expired', 'revoked', 'used', 'unknown']);

test('an expired invitation is marked Expired, by the scheduled task per tenant or on use', function (): void {
    $acme = $this->invitations->invite(['email' => 'one@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA);
    $beta = invitationInviteToBeta($this->world, 'two@example.test');
    $this->travel(73)->hours();

    $this->artisan('invitations:expire')->expectsOutputToContain('Expired 1 invitation(s)')->assertSuccessful();

    expect($acme->fresh()->status)->toBe(InvitationStatus::Expired)
        ->and(TenantContext::current()->run($this->world->beta, fn () => $beta->fresh()->status))->toBe(InvitationStatus::Pending)
        ->and(fn () => TenantContext::current()->run($this->world->beta, fn () => $this->invitations->acceptAsNewIdentity($beta, 'Two', 'Tr1cky-Ledger-Horse')))->toThrow(InvitationUnavailable::class)
        ->and(TenantContext::current()->run($this->world->beta, fn () => $beta->fresh()->status))->toBe(InvitationStatus::Expired)
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'invitation_expired')->count()))->toBe(1);
});

test('a resend replaces the link: the old one stops working', function (): void {
    $invitation = invitationInviteToBeta($this->world, 'brand.new@example.test');
    $old = invitationLastLink();

    TenantContext::current()->run($this->world->beta, fn () => $this->invitations->resend($invitation, $this->world->adminB));
    $new = invitationLastLink();

    expect($new)->not->toBe($old);

    $this->get($old)->assertNotFound();
    $this->get($new)->assertRedirect(route('invitations.show'));
});

test('a suspended or cancelled tenant activates no membership — whoever sent the invitation', function (TenantStatus $status, string $source): void {
    $invitation = $source === 'administrator'
        ? invitationInviteToBeta($this->world, 'brand.new@example.test')
        : TenantContext::current()->run($this->world->beta, fn () => $this->invitations->inviteEmployee(Employee::factory()->create(['email' => 'brand.new@example.test']), $this->world->adminB, 'conversion'));
    $this->world->beta->update(['status' => $status]);

    expect(fn () => TenantContext::current()->run($this->world->beta, fn () => $this->invitations->acceptAsNewIdentity($invitation, 'Brand New', 'Tr1cky-Ledger-Horse')))->toThrow(InvitationUnavailable::class)
        ->and(User::query()->where('email', 'brand.new@example.test')->exists())->toBeFalse()
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'invitation_refused')->sole()->getAttribute('changes')))->toMatchArray(['reason' => 'tenant_unusable']);
})->with([TenantStatus::Suspended, TenantStatus::Cancelled])->with(['administrator', 'conversion']);

test('an invitation never lifts a suspension; it re-activates a revoked membership with the invited roles only', function (): void {
    // A suspended member cannot be invited again through the screen; an invitation that reaches one
    // (sent before the suspension) still cannot lift it.
    $invitation = $this->invitations->invite(['email' => 'chen.second@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA);
    TenantInvitation::query()->whereKey($invitation->id)->update(['email' => 'chen@example.test']);

    expect(fn () => $this->invitations->accept($invitation, $this->world->personC))->toThrow(InvitationUnavailable::class, 'suspended')
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and(membershipStatusOf($this->world->personC))->toBe(AccessState::Suspended);

    app(StaffAccessService::class)->revoke($this->world->personB, $this->world->adminA, 'Left');
    $reinvite = $this->invitations->invite(['email' => 'bala@example.test', 'roles' => [Role::byKeyOrFail('employee')->id]], $this->world->adminA);
    $this->invitations->accept($reinvite, $this->world->personB);

    expect(membershipStatusOf($this->world->personB))->toBe(AccessState::Active)
        ->and($this->world->personB->fresh()->getRoleNames()->all())->toBe(['employee'])
        ->and(AuditLog::query()->where('action', 'membership_activated')->where('auditable_id', $this->world->personB->id)->exists())->toBeTrue();
});

test('acceptance re-checks the promise: roles that no longer exist, or an inviter who lost authority, void it', function (): void {
    $custom = app(RoleAssignmentService::class)->createRole('Sourcer', [], $this->world->adminA);
    $deleted = $this->invitations->invite(['email' => 'one@example.test', 'roles' => [$custom->id]], $this->world->adminA);
    app(RoleAssignmentService::class)->deleteRole($custom, $this->world->adminA);

    $byB = TenantContext::current()->run($this->world->beta, fn () => $this->invitations->invite(['email' => 'two@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminB));
    TenantMembership::query()->where('tenant_id', $this->world->beta->id)->where('user_id', $this->world->adminB->id)->update(['status' => AccessState::Suspended->value]);
    StaffAccessService::invalidateDecisions();

    expect(fn () => $this->invitations->acceptAsNewIdentity($deleted, 'One', 'Tr1cky-Ledger-Horse'))->toThrow(InvitationUnavailable::class)
        ->and(fn () => TenantContext::current()->run($this->world->beta, fn () => $this->invitations->acceptAsNewIdentity($byB, 'Two', 'Tr1cky-Ledger-Horse')))->toThrow(InvitationUnavailable::class)
        ->and(User::query()->whereIn('email', ['one@example.test', 'two@example.test'])->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'invitation_refused')->sole()->getAttribute('changes'))->toMatchArray(['reason' => 'roles_changed'])
        ->and(TenantContext::current()->run($this->world->beta, fn () => AuditLog::query()->where('action', 'invitation_refused')->sole()->getAttribute('changes')))->toMatchArray(['reason' => 'inviter_authority_changed']);
});

test('an identity the platform disabled joins nothing', function (): void {
    $invitation = invitationInviteToBeta($this->world, 'bala@example.test');
    $this->artisan('identity:disable bala@example.test --reason="Compromised"')->assertSuccessful();

    expect(fn () => TenantContext::current()->run($this->world->beta, fn () => $this->invitations->accept($invitation, $this->world->personB->fresh())))->toThrow(InvitationUnavailable::class)
        ->and(TenantMembership::query()->where('tenant_id', $this->world->beta->id)->where('user_id', $this->world->personB->id)->exists())->toBeFalse();
});

test('the invitations list and its actions are the tenant\'s own', function (): void {
    $acme = $this->invitations->invite(['email' => 'acme.only@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA);
    $beta = invitationInviteToBeta($this->world, 'beta.only@example.test');
    $this->actingAs($this->world->adminA);

    Livewire::test(ListTenantInvitations::class)
        ->assertCanSeeTableRecords([$acme])
        ->assertCountTableRecords(1);

    expect($this->world->adminA->can('update', TenantContext::current()->run($this->world->beta, fn () => $beta->fresh())))->toBeFalse()
        ->and(fn () => $this->invitations->revoke($beta, $this->world->adminA, 'Not ours'))->toThrow(DomainException::class)
        ->and(TenantContext::current()->run($this->world->beta, fn () => $beta->fresh()->status))->toBe(InvitationStatus::Pending);

    $this->actingAs($this->world->personB)->get('/admin/acme/tenant-invitations')->assertForbidden();
});
