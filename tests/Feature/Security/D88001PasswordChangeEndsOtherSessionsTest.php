<?php

use App\Http\Middleware\EnsureCandidateSessionIsCurrent;
use App\Models\AuditLog;
use App\Services\CandidatePortalService;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__.'/D88001Helpers.php';

/**
 * D8.8-001: a candidate password change or reset ends every other candidate session and every
 * "remember me" cookie; the session that made the change carries on; staff and other candidates
 * are unaffected.
 */
beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function d88StaleSessionFor($account): array
{
    return [EnsureCandidateSessionIsCurrent::SESSION_KEY => app(CandidatePortalService::class)->sessionFingerprint($account)];
}

test('signing in stores a session fingerprint and the session keeps working', function (): void {
    $account = d88Account();

    $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'Secret#12345']);

    expect(session(EnsureCandidateSessionIsCurrent::SESSION_KEY))->toBe(app(CandidatePortalService::class)->sessionFingerprint($account->fresh()));
    $this->get(route('portal.dashboard'))->assertOk();
});

test('a session opened before a password reset is ended on its next request', function (): void {
    $account = d88Account();
    $oldSession = d88StaleSessionFor($account);

    app(CandidatePortalService::class)->setPassword($account->fresh(), 'NewSecret#12345');

    $this->actingAs($account->fresh(), 'candidate')->withSession($oldSession)
        ->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));

    $this->assertGuest('candidate');
    expect(AuditLog::query()->where('action', 'portal_session_ended')->where('auditable_id', $account->id)->exists())->toBeTrue();
});

test('a stale session is not treated as the owner on self-scheduling pages', function (): void {
    $account = d88Account();
    $invitation = d88Invitation($account);
    $oldSession = d88StaleSessionFor($account);
    app(CandidatePortalService::class)->setPassword($account->fresh(), 'NewSecret#12345');

    $this->actingAs($account->fresh(), 'candidate')->withSession($oldSession)
        ->get(route('portal.schedule.show', $invitation->public_id))->assertNotFound();
});

test('the reset link signs the current browser in with a fresh session that stays valid', function (): void {
    $account = d88Account();
    $link = app(CandidatePortalService::class)->passwordLink($account);

    $this->post($link, ['password' => 'NewSecret#12345', 'password_confirmation' => 'NewSecret#12345'])->assertRedirect(route('portal.dashboard'));

    $this->get(route('portal.dashboard'))->assertOk();
    $this->assertAuthenticatedAs($account->fresh(), 'candidate');
});

test('a password change replaces the remember-me token, so other devices cannot sign back in', function (): void {
    $account = d88Account(['remember_token' => 'old-remember-token']);

    app(CandidatePortalService::class)->setPassword($account, 'NewSecret#12345');

    expect($account->fresh()->remember_token)->not->toBe('old-remember-token')->not->toBeEmpty();
});

test('the old password stops working and the new one works', function (): void {
    $account = d88Account();
    app(CandidatePortalService::class)->setPassword($account, 'NewSecret#12345');

    $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'Secret#12345'])->assertSessionHasErrors('email');
    $this->assertGuest('candidate');

    $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'NewSecret#12345'])->assertRedirect(route('portal.dashboard'));
});

test('another candidate\'s session and staff sessions are unaffected', function (): void {
    $changing = d88Account();
    $other = d88Account();
    $otherSession = d88StaleSessionFor($other);
    $staff = d88Staff();

    app(CandidatePortalService::class)->setPassword($changing, 'NewSecret#12345');

    $this->actingAs($other, 'candidate')->withSession($otherSession)->get(route('portal.dashboard'))->assertOk();
    $this->actingAs($staff, 'web')->get('/admin/acme')->assertOk();
});

test('a used reset link cannot be replayed, and an expired one fails', function (): void {
    $account = d88Account();
    $link = app(CandidatePortalService::class)->passwordLink($account);
    $this->post($link, ['password' => 'NewSecret#12345', 'password_confirmation' => 'NewSecret#12345']);
    auth('candidate')->logout();

    $this->post($link, ['password' => 'Another#12345', 'password_confirmation' => 'Another#12345'])->assertForbidden();

    $fresh = app(CandidatePortalService::class)->passwordLink($account->fresh());
    $this->travel(49)->hours();
    $this->get($fresh)->assertForbidden();
});
