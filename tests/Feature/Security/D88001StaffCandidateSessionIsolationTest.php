<?php

use App\Filament\Resources\Candidates\CandidateResource;
use App\Http\Middleware\UseCandidateSessionContext;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__.'/D88001Helpers.php';

/**
 * D8.8-001 identity boundary: staff and candidate authentication use separate session cookies and
 * guards, and neither ever reads the other.
 */
beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('portal requests use the candidate session cookie; staff and public pages use the staff cookie', function (): void {
    $portal = $this->get(route('portal.login'));
    $staff = $this->get('/admin/login');
    $public = $this->get(route('careers.index'));

    expect($portal->getCookie(config('session.candidate_cookie'), false))->not->toBeNull()
        ->and($portal->getCookie(config('session.cookie'), false))->toBeNull()
        ->and($staff->getCookie(config('session.cookie'), false))->not->toBeNull()
        ->and($staff->getCookie(config('session.candidate_cookie'), false))->toBeNull()
        ->and($public->getCookie(config('session.candidate_cookie'), false))->toBeNull()
        ->and(config('session.candidate_cookie'))->not->toBe(config('session.cookie'));
});

test('both cookies keep the same Secure, HttpOnly and SameSite settings', function (): void {
    config(['session.secure' => true]);

    $cookie = $this->get(route('portal.login'))->getCookie(config('session.candidate_cookie'), false);

    expect($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

test('a candidate sign-in writes only the candidate session cookie', function (): void {
    $account = d88Account();

    $response = $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'Secret#12345']);

    expect($response->getCookie(config('session.candidate_cookie'), false))->not->toBeNull()
        ->and($response->getCookie(config('session.cookie'), false))->toBeNull();
});

test('a signed-in staff user is nobody on the candidate portal', function (): void {
    $this->actingAs(d88Staff(), 'web');

    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    $this->get(route('portal.profile.edit'))->assertRedirect(route('portal.login'));
    $this->get(route('portal.step-up.show'))->assertRedirect(route('portal.login'));
});

test('a signed-in candidate is nobody on staff pages', function (): void {
    $this->actingAs(d88Account(), 'candidate');

    $this->get('/admin/acme')->assertRedirect(route('filament.admin.auth.login'));
    $this->get(CandidateResource::getUrl('index'))->assertRedirect(route('filament.admin.auth.login'));
});

test('on a portal request the default guard is the candidate guard, elsewhere the staff guard', function (): void {
    $this->get(route('portal.login'));
    expect(auth()->getDefaultDriver())->toBe(UseCandidateSessionContext::CANDIDATE_GUARD);

    $this->get(route('careers.index'));
    expect(auth()->getDefaultDriver())->toBe(UseCandidateSessionContext::STAFF_GUARD);
});

test('a staff user can never be recorded as a candidate actor', function (): void {
    expect(fn () => AuditLog::asCandidate(User::factory()->create(), fn () => null))->toThrow(InvalidArgumentException::class);
});
