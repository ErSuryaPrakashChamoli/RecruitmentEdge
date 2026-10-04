<?php

use App\Filament\Pages\Auth\StaffLogin;
use App\Filament\Pages\Profile;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\Auth\ResetPassword as ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailChange;
use App\Services\Identity\CredentialService;
// Phase 8.7 (SEC-87-02): Filament resolves this encrypted subclass of its reset-password mail.
use App\Services\Identity\StaffAccessService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->member = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('recruiter');
});

// SaaS-2: administrators no longer create logins with a password (people join by invitation and
// choose their own); the policy still guards every password an administrator sets.
test('an administrator-set password must meet the staff policy', function (string $weak): void {
    actingAs($this->chro);
    $before = $this->member->fresh()->password;

    Livewire::test(EditUser::class, ['record' => $this->member->getRouteKey()])
        ->fillForm(['password' => $weak, 'password_confirmation' => $weak])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($this->member->fresh()->password)->toBe($before);
})->with(['too short' => 'Ab1!short', 'no symbol' => 'Abcdefgh1234', 'no capital' => 'abcdefgh12!!', 'common word' => 'Welcome@2026!', 'common word decorated' => 'P@ssword12345!']);

test('the password policy is also enforced by the service, not only the form', function (): void {
    expect(fn () => app(CredentialService::class)->setPasswordByAdministrator($this->member, 'password', $this->chro))->toThrow(DomainException::class);
});

test('an administrator reset of someone\'s password is audited without the value and signs them out everywhere', function (): void {
    actingAs($this->chro);

    Livewire::test(EditUser::class, ['record' => $this->member->getRouteKey()])
        ->fillForm(['password' => 'Tr1cky-Ledger-Horse', 'password_confirmation' => 'Tr1cky-Ledger-Horse'])
        ->call('save')
        ->assertHasNoFormErrors();

    $audit = AuditLog::query()->where('action', 'password_reset_by_admin')->sole();

    expect(Hash::check('Tr1cky-Ledger-Horse', $this->member->fresh()->password))->toBeTrue()
        ->and($this->member->fresh()->session_epoch)->toBe(1)
        ->and($audit->getAttribute('changes'))->toBe(['by_user_id' => $this->chro->id])
        ->and(str_contains(json_encode(AuditLog::query()->get()->toArray()), 'Tr1cky-Ledger-Horse'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'password_changed')->exists())->toBeFalse();
});

test('changing your own password on the profile page is audited', function (): void {
    actingAs($this->member);

    Livewire::test(Profile::class)
        ->fillForm(['password' => 'Quiet-Harbour-7th', 'passwordConfirmation' => 'Quiet-Harbour-7th', 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AuditLog::query()->where('action', 'password_changed')->where('auditable_id', $this->member->id)->exists())->toBeTrue();
});

test('a staff password reset link is sent, audited, single-use and expires', function (): void {
    Notification::fake();

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $this->member->email])->call('request');

    Notification::assertSentTo($this->member, ResetPasswordNotification::class);
    expect(AuditLog::query()->where('action', 'password_reset_requested')->where('auditable_id', $this->member->id)->exists())->toBeTrue();

    $token = PasswordBroker::broker()->createToken($this->member);

    Livewire::test(ResetPassword::class, ['email' => $this->member->email, 'token' => $token])
        ->set('password', 'Brisk-Copper-Lantern9')
        ->set('passwordConfirmation', 'Brisk-Copper-Lantern9')
        ->call('resetPassword')
        ->assertHasNoErrors();

    expect(Hash::check('Brisk-Copper-Lantern9', $this->member->fresh()->password))->toBeTrue()
        ->and($this->member->fresh()->session_epoch)->toBe(1)
        ->and(AuditLog::query()->where('action', 'password_reset_completed')->exists())->toBeTrue()
        ->and(PasswordBroker::broker()->tokenExists($this->member->fresh(), $token))->toBeFalse();

    $expired = PasswordBroker::broker()->createToken($this->member->fresh());
    $this->travel(61)->minutes();

    expect(PasswordBroker::broker()->tokenExists($this->member->fresh(), $expired))->toBeFalse();
});

test('a suspended or revoked login is never sent a reset link', function (): void {
    Notification::fake();
    app(StaffAccessService::class)->revoke($this->member, $this->chro, 'Left');

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $this->member->email])->call('request');

    Notification::assertNotSentTo($this->member, ResetPasswordNotification::class);
});

test('an administrator\'s email change applies only once the new address is verified', function (): void {
    Notification::fake();
    actingAs($this->chro);
    $old = $this->member->email;

    Livewire::test(EditUser::class, ['record' => $this->member->getRouteKey()])
        ->fillForm(['email' => 'moved@example.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->member->fresh()->email)->toBe($old)
        ->and(AuditLog::query()->where('action', 'email_change_requested')->sole()->getAttribute('changes'))->toMatchArray(['email' => 'moved@example.com', 'pending_verification' => true]);

    Notification::assertSentOnDemand(VerifyEmailChange::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'moved@example.com');
});

test('changing your own email on the profile page also waits for verification', function (): void {
    Notification::fake();
    actingAs($this->member);
    $old = $this->member->email;

    Livewire::test(Profile::class)->fillForm(['email' => 'self.moved@example.com', 'currentPassword' => 'password'])->call('save')->assertHasNoFormErrors();

    expect($this->member->fresh()->email)->toBe($old)
        ->and(AuditLog::query()->where('action', 'email_change_requested')->where('auditable_id', $this->member->id)->exists())->toBeTrue();
});

test('signing out of other sessions keeps this one and ends the rest', function (): void {
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([['id' => 'other-device', 'user_id' => $this->member->id, 'payload' => '', 'last_activity' => time()]]);
    actingAs($this->member);

    Livewire::test(Profile::class)->callAction('signOutOtherSessions');

    expect(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse()
        ->and($this->member->fresh()->session_epoch)->toBe(1)
        ->and(AuditLog::query()->where('action', 'signed_out_everywhere')->exists())->toBeTrue();
});

test('signing someone else out everywhere needs users.access.manage in scope', function (): void {
    $manager = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('manager');

    expect(fn () => app(CredentialService::class)->signOutEverywhere($this->member, $manager))->toThrow(DomainException::class, 'users.access.manage');

    app(CredentialService::class)->signOutEverywhere($this->member, $this->chro);

    expect($this->member->fresh()->session_epoch)->toBe(1);
});

test('repeated failures lock the account whatever the IP, even with the right password, and it is audited', function (): void {
    // Filament's own per-IP limiter is cleared between attempts: as if every attempt came from a
    // different address.
    $perIp = 'livewire-rate-limiter:'.sha1(StaffLogin::class.'|authenticate|'.request()->ip());

    foreach (range(1, StaffLogin::MAX_FAILURES) as $attempt) {
        RateLimiter::clear($perIp);
        Livewire::test(StaffLogin::class)->fillForm(['email' => $this->member->email, 'password' => 'wrong-'.$attempt])->call('authenticate');
    }

    RateLimiter::clear($perIp);
    Livewire::test(StaffLogin::class)->fillForm(['email' => $this->member->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);

    expect(auth()->check())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'login_failed')->where('auditable_id', $this->member->id)->count())->toBe(StaffLogin::MAX_FAILURES)
        ->and(AuditLog::query()->where('action', 'login_locked_out')->where('auditable_id', $this->member->id)->exists())->toBeTrue();
});

test('signing out is audited and audit rows carry the request correlation id', function (): void {
    $this->actingAs($this->member)->get('/admin/acme')->assertOk();
    $this->post('/admin/logout', [], ['X-Request-Id' => 'req-identity-0001']);

    $logout = AuditLog::query()->where('action', 'logout')->sole();

    expect($logout->auditable_id)->toBe($this->member->id)
        ->and($logout->request_id)->toBe('req-identity-0001');
});
