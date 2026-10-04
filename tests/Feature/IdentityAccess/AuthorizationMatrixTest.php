<?php

use App\Filament\Pages\Auth\StaffLogin;
use App\Notifications\StaffDatabaseNotification;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-2 authorisation matrix (prompt §23). One identity per row; each tenant's answer comes from
 * that identity's membership and roles in that tenant only.
 *
 *   personA: Acme = Active manager, Beta = Active recruiter
 *   personB: Acme = Active recruiter
 *   personC: Acme = Suspended
 *   personD: no membership
 *   personE: Acme and Beta = Active recruiter
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
});

dataset('panel matrix', [
    'A enters Acme as manager' => ['personA', '/admin/acme/automation-rules', 200],
    'A enters Beta as recruiter' => ['personA', '/admin/beta/candidates', 200],
    'A is refused manager-only work in Beta' => ['personA', '/admin/beta/automation-rules', 403],
    'B works in Acme as recruiter' => ['personB', '/admin/acme/candidates', 200],
    'B is refused manager-only work in Acme' => ['personB', '/admin/acme/automation-rules', 403],
    'B never reaches Beta' => ['personB', '/admin/beta', 404],
    'B never reaches a Beta page' => ['personB', '/admin/beta/candidates', 404],
    'C (suspended) is signed out of Acme' => ['personC', '/admin/acme', 302],
    'D (no membership) is signed out' => ['personD', '/admin/acme', 302],
    'E enters Acme' => ['personE', '/admin/acme/candidates', 200],
    'E enters Beta' => ['personE', '/admin/beta/candidates', 200],
]);

test('each identity reaches exactly what its membership and role in that tenant allow', function (string $person, string $url, int $status): void {
    $response = $this->actingAs($this->world->{$person})->get($url);

    expect($response->status())->toBe($status);

    if ($status === 302) {
        $response->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $this->assertGuest();
    }
})->with('panel matrix');

dataset('sign-in matrix', [
    'A signs in' => ['personA', true],
    'B signs in' => ['personB', true],
    'C (suspended everywhere it belongs) cannot' => ['personC', false],
    'D (no membership) cannot' => ['personD', false],
    'E signs in' => ['personE', true],
]);

test('sign-in establishes the global identity only for people who can enter a tenant, with the same failure as a wrong password', function (string $person, bool $signsIn): void {
    RateLimiter::clear('livewire-rate-limiter:'.sha1(StaffLogin::class.'|authenticate|'.request()->ip()));

    $login = Livewire::test(StaffLogin::class)->fillForm(['email' => $this->world->{$person}->email, 'password' => 'password'])->call('authenticate');

    if ($signsIn) {
        $login->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->world->{$person});

        return;
    }

    $login->assertHasFormErrors(['email']);
    $this->assertGuest();

    $wrong = Livewire::test(StaffLogin::class)->fillForm(['email' => $this->world->{$person}->email, 'password' => 'not-the-password'])->call('authenticate');

    expect($login->errors()->get('data.email'))->toBe($wrong->errors()->get('data.email'));
})->with('sign-in matrix');

dataset('permission matrix', [
    'A in Acme: manager permission' => ['personA', 'acme', 'automation.view', true],
    'A in Beta: recruiter permission' => ['personA', 'beta', 'candidates.viewAny', true],
    'A in Beta: manager-only permission' => ['personA', 'beta', 'automation.view', false],
    'B in Acme: recruiter permission' => ['personB', 'acme', 'candidates.viewAny', true],
    'B in Beta: nothing' => ['personB', 'beta', 'candidates.viewAny', false],
    'C in Acme: nothing while suspended' => ['personC', 'acme', 'candidates.viewAny', false],
    'D anywhere: nothing' => ['personD', 'acme', 'candidates.viewAny', false],
    'E in Beta: recruiter permission' => ['personE', 'beta', 'candidates.viewAny', true],
]);

test('permission checks (jobs, services, Copilot) answer for the tenant they run in', function (string $person, string $tenant, string $permission, bool $allowed): void {
    $user = $this->world->{$person}->fresh();

    expect(TenantContext::current()->run($this->world->{$tenant}, fn (): bool => $user->can($permission)))->toBe($allowed)
        ->and(TenantContext::current()->runWithoutTenant(fn (): bool => $user->can($permission)))->toBeFalse();
})->with('permission matrix');

test('a notification for one tenant is never shown in the other, even to the same person', function (): void {
    TenantContext::current()->run($this->world->beta, fn () => $this->world->personA->notify(new StaffDatabaseNotification(Notification::make()->title('Beta-only alert')->getDatabaseMessage())));

    expect($this->world->personA->notifications()->count())->toBe(0)
        ->and(TenantContext::current()->run($this->world->beta, fn () => $this->world->personA->notifications()->count()))->toBe(1);
});
