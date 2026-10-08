<?php

use App\Filament\Auth\StaffAppAuthentication;
use App\Filament\Pages\Auth\StaffLogin;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\MfaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config(['identity.mfa.enforce' => true]);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->manager = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('manager');
    $this->recruiter = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('recruiter');
    $this->mfa = app(MfaService::class);
    $this->provider = collect(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders())->first();
});

function mfaEnrol(User $user): string
{
    $secret = app(StaffAppAuthentication::class)->generateSecret();
    $user->saveAppAuthenticationSecret($secret);
    $user->saveAppAuthenticationRecoveryCodes(['code-one-1111', 'code-two-2222']);

    return $secret;
}

test('CHRO, VP HR and Manager, and privileged permission holders, must use MFA; others need not', function (): void {
    $vp = User::factory()->create()->assignRole('vp_hr');
    $auditor = User::factory()->create()->assignRole(Role::create(['name' => 'auditor'])->givePermissionTo('audit.view'));

    expect($this->mfa->isRequiredFor($this->chro))->toBeTrue()
        ->and($this->mfa->isRequiredFor($vp))->toBeTrue()
        ->and($this->mfa->isRequiredFor($this->manager))->toBeTrue()
        ->and($this->mfa->isRequiredFor($auditor))->toBeTrue()
        ->and($this->mfa->isRequiredFor($this->recruiter))->toBeFalse();
});

test('a privileged user who has not enrolled is sent to enrolment before any panel page', function (): void {
    $this->actingAs($this->manager)->get('/admin/acme')->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
    $this->actingAs($this->recruiter)->get('/admin/acme')->assertOk();

    mfaEnrol($this->manager);

    $this->actingAs($this->manager->fresh())->get('/admin/acme')->assertOk();
});

test('the enrolment page opens outside any tenant, without the tenant-owned notifications', function (): void {
    $this->actingAs($this->manager)->get(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl())
        ->assertOk()
        ->assertDontSeeLivewire(DatabaseNotifications::class);

    $this->actingAs($this->recruiter)->get('/admin/acme')->assertSeeLivewire(DatabaseNotifications::class);
});

test('the sign-in challenge is enforced once enrolled', function (): void {
    $secret = mfaEnrol($this->manager);

    Livewire::test(StaffLogin::class)
        ->fillForm(['email' => $this->manager->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertSet('userUndertakingMultiFactorAuthentication', fn ($value) => filled($value));

    expect(auth()->check())->toBeFalse();

    $component = Livewire::test(StaffLogin::class)->fillForm(['email' => $this->manager->email, 'password' => 'password'])->call('authenticate');
    $component->set('data.multiFactor.app.code', '000000')->call('authenticate');

    expect(auth()->check())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'mfa_challenge_failed')->where('auditable_id', $this->manager->id)->exists())->toBeTrue();

    $component->set('data.multiFactor.app.code', $this->provider->getCurrentCode($this->manager->fresh(), $secret))->call('authenticate');

    expect(auth()->id())->toBe($this->manager->id);
});

test('enrolment is audited and the secret never reaches the audit log or serialization', function (): void {
    $secret = mfaEnrol($this->manager);

    expect(AuditLog::query()->where('action', 'mfa_enabled')->where('auditable_id', $this->manager->id)->exists())->toBeTrue()
        ->and($this->manager->fresh()->mfa_enabled_at)->not->toBeNull()
        ->and(str_contains(json_encode(AuditLog::query()->get()->toArray()), $secret))->toBeFalse()
        ->and(str_contains(json_encode($this->manager->fresh()->toArray()), $secret))->toBeFalse()
        ->and(str_contains(json_encode($this->manager->fresh()->toArray()), 'code-one-1111'))->toBeFalse();
});

test('someone whose role requires MFA cannot turn it off themselves', function (): void {
    mfaEnrol($this->manager);

    expect(fn () => $this->manager->fresh()->saveAppAuthenticationSecret(null))->toThrow(DomainException::class, 'required for your role')
        ->and($this->mfa->isEnabledFor($this->manager->fresh()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'mfa_disable_refused')->exists())->toBeTrue();

    $this->actingAs($this->manager->fresh());
    $disable = collect($this->provider->getActions())->first(fn ($action) => $action->getName() === 'disableAppAuthentication');

    expect($disable?->isVisible())->toBeFalse();
});

test('someone without the requirement may turn MFA off, and it is audited', function (): void {
    mfaEnrol($this->recruiter);

    $this->recruiter->fresh()->saveAppAuthenticationSecret(null);

    expect($this->mfa->isEnabledFor($this->recruiter->fresh()))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'mfa_disabled')->where('auditable_id', $this->recruiter->id)->exists())->toBeTrue();
});

test('an administrator can reset someone\'s MFA; it is audited and they are signed out', function (): void {
    mfaEnrol($this->manager);

    $this->mfa->resetFor($this->manager->fresh(), $this->chro);

    expect($this->mfa->isEnabledFor($this->manager->fresh()))->toBeFalse()
        ->and($this->manager->fresh()->session_epoch)->toBe(1)
        ->and(AuditLog::query()->where('action', 'mfa_reset_by_admin')->exists())->toBeTrue();
});

test('resetting MFA needs users.access.manage and is never for yourself', function (): void {
    expect(fn () => $this->mfa->resetFor($this->chro, $this->chro))->toThrow(DomainException::class, 'your own access')
        ->and(fn () => $this->mfa->resetFor($this->recruiter, $this->manager))->toThrow(DomainException::class, 'users.access.manage');
});

test('regenerating recovery codes is audited', function (): void {
    mfaEnrol($this->recruiter);

    $this->recruiter->fresh()->saveAppAuthenticationRecoveryCodes(['new-code-3333']);

    expect(AuditLog::query()->where('action', 'mfa_recovery_codes_regenerated')->exists())->toBeTrue();
});
