<?php

use App\Filament\Pages\Auth\StaffRequestPasswordReset;
use App\Filament\Pages\Profile;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Notifications\Auth\ResetPassword as ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailChange;
use App\Services\Identity\CredentialService;
use App\Services\Identity\MfaService;
use App\Services\Identity\StaffAccessService;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-2: a password, an email address, MFA and sessions belong to the global identity and open
 * every tenant it belongs to. A tenant may manage them only for an identity that belongs to it
 * alone — otherwise one tenant could take over a person's account in another. And no screen ever
 * reveals whether an address has an identity on the platform.
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
});

/**
 * The titles of the notifications the page sent (read before any assertNotified consumes them).
 *
 * @return list<string>
 */
function credentialShownNotifications(): array
{
    return collect(Livewire::test(Notifications::class)->instance()->notifications)->map(fn ($notification) => (string) $notification->getTitle())->values()->all();
}

test('a tenant cannot set the password, change the email, reset MFA or end the sessions of a person who also belongs to another tenant', function (): void {
    $credentials = app(CredentialService::class);
    $shared = $this->world->personA;
    $before = $shared->fresh()->password;
    $shared->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    expect(fn () => $credentials->setPasswordByAdministrator($shared, 'Tr1cky-Ledger-Horse', $this->world->adminA))->toThrow(DomainException::class, 'another organisation')
        ->and(fn () => $credentials->requestEmailChange($shared, 'takeover@example.test', $this->world->adminA))->toThrow(DomainException::class, 'another organisation')
        ->and(fn () => app(MfaService::class)->resetFor($shared, $this->world->adminA))->toThrow(DomainException::class, 'another organisation')
        ->and(fn () => $credentials->signOutEverywhere($shared, $this->world->adminA))->toThrow(DomainException::class, 'another organisation')
        ->and($shared->fresh()->password)->toBe($before)
        ->and(filled($shared->fresh()->getAppAuthenticationSecret()))->toBeTrue()
        ->and($shared->fresh()->session_epoch)->toBe(0);
});

test('a tenant still manages the credentials of an identity that belongs to it alone', function (): void {
    app(CredentialService::class)->setPasswordByAdministrator($this->world->personB, 'Tr1cky-Ledger-Horse', $this->world->adminA);

    expect(Hash::check('Tr1cky-Ledger-Horse', $this->world->personB->fresh()->password))->toBeTrue();
});

test('the edit screen offers no credential change for a shared identity, and ignores a tampered name', function (): void {
    $this->actingAs($this->world->adminA);

    Livewire::test(EditUser::class, ['record' => $this->world->personA->getRouteKey()])
        ->assertFormFieldDisabled('email')
        ->assertFormFieldDisabled('name')
        ->assertFormFieldHidden('password')
        ->assertActionHidden('resetMfa')
        ->assertActionHidden('signOutEverywhere')
        ->assertActionVisible('suspendAccess')
        ->set('data.name', 'Renamed By Acme')
        ->call('save');

    Livewire::test(EditUser::class, ['record' => $this->world->personB->getRouteKey()])
        ->assertFormFieldEnabled('email')
        ->assertFormFieldVisible('password')
        ->assertActionVisible('signOutEverywhere');

    expect($this->world->personA->fresh()->name)->toBe('Asha Two-Tenants');
});

test('"Forgot password" answers the same for a known address, an unknown one and one asked again too soon', function (): void {
    Notification::fake();
    $shown = [];

    foreach (['bala@example.test', 'nobody@example.test', 'bala@example.test', 'chen@example.test'] as $email) {
        // The page's own per-IP limit (2 a minute) is the same for every address; lifted here so
        // each request reaches the reset broker.
        RateLimiter::clear('livewire-rate-limiter:'.sha1(StaffRequestPasswordReset::class.'|request|'.request()->ip()));
        Livewire::test(StaffRequestPasswordReset::class)->fillForm(['email' => $email])->call('request');
        $shown[] = credentialShownNotifications();
    }

    expect(array_unique(array_map('json_encode', $shown)))->toHaveCount(1)
        ->and($shown[0])->toBe(['We have emailed your password reset link.']);

    // Only the first request for a person who can enter a tenant sent a link: the unknown address,
    // the too-soon repeat and the suspended member (no tenant left) got none — and saw no difference.
    Notification::assertSentToTimes($this->world->personB, ResetPasswordNotification::class, 1);
    Notification::assertNotSentTo($this->world->personC, ResetPasswordNotification::class);
});

test('changing your own email to an address another identity uses looks like any other change, and sends it nothing', function (): void {
    Notification::fake();
    $this->actingAs($this->world->personB);

    Livewire::test(Profile::class)
        ->fillForm(['email' => 'chief@beta.test', 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasNoFormErrors();

    $takenShown = credentialShownNotifications();

    Livewire::test(Profile::class)
        ->fillForm(['email' => 'free.address@example.test', 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(credentialShownNotifications())->toBe($takenShown)
        ->and($this->world->personB->fresh()->email)->toBe('bala@example.test');

    Notification::assertSentOnDemand(VerifyEmailChange::class, fn ($notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'free.address@example.test');
    Notification::assertNotSentTo($this->world->adminB, VerifyEmailChange::class);
    Notification::assertSentOnDemandTimes(VerifyEmailChange::class, 1);
});

test('an administrator\'s email change to a taken address is recorded and silently sends no link', function (): void {
    Notification::fake();

    app(CredentialService::class)->requestEmailChange($this->world->personB, 'CHIEF@beta.test', $this->world->adminA);

    Notification::assertNothingSentTo($this->world->personB);
    Notification::assertSentOnDemandTimes(VerifyEmailChange::class, 0);

    expect(AuditLog::query()->where('action', 'email_change_requested')->where('auditable_id', $this->world->personB->id)->exists())->toBeTrue()
        ->and($this->world->personB->fresh()->email)->toBe('bala@example.test');
});

test('a suspended person keeps their sessions while another tenant is still theirs, and the suspended tenant is closed', function (): void {
    app(StaffAccessService::class)->suspend($this->world->personE, $this->world->adminA, 'Paused');

    $this->actingAs($this->world->personE->fresh());

    $this->get('/admin/acme')->assertNotFound();
    $this->get('/admin/beta')->assertOk();
});
