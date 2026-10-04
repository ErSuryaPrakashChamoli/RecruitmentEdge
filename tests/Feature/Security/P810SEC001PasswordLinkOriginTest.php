<?php

use App\Filament\Pages\Auth\StaffRequestPasswordReset;
use App\Mail\CandidatePortalLink;
use App\Models\Candidate;
use App\Models\CandidatePortalAccount;
use App\Models\User;
use App\Notifications\Auth\ResetPassword as ResetPasswordNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/*
 * Phase 8.10 (P810-SEC-001): a password link is emailed during an anonymous request, so the
 * request's Host (or, behind a trusted proxy, X-Forwarded-Host) must never choose the link's host.
 * Every link carries APP_URL's host and still opens the set-password / reset page.
 */

afterEach(function (): void {
    SymfonyRequest::setTrustedHosts([]);
});

/**
 * The root a request is sent to and the headers it carries, per way the host can be forged.
 *
 * @return array<string, array{0: Closure(): string, 1: array<string, string>}>
 */
dataset('request origins', fn (): array => [
    'the application host' => [fn (): string => rtrim((string) config('app.url'), '/'), []],
    'a forged Host header' => [fn (): string => 'http://evil.example', []],
    'a forged X-Forwarded-Host through a trusted proxy' => [fn (): string => rtrim((string) config('app.url'), '/'), ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'http']],
]);

function expectApplicationOrigin(string $link): void
{
    expect(parse_url($link, PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST))
        ->and(parse_url($link, PHP_URL_PORT))->toBe(parse_url((string) config('app.url'), PHP_URL_PORT))
        ->and($link)->not->toContain('evil.example');
}

test('a candidate set-password link always uses the application host and still opens', function (Closure $root, array $headers): void {
    TrustProxies::at('*');
    Mail::fake();
    $account = CandidatePortalAccount::factory()->create(['candidate_id' => Candidate::factory()->create()->id]);

    $this->withHeaders($headers)->post($root().'/portal/acme/password/forgot', ['email' => $account->email])->assertRedirect();

    $link = null;
    Mail::assertQueued(CandidatePortalLink::class, function (CandidatePortalLink $mail) use (&$link): bool {
        $link = $mail->url;

        return true;
    });

    expectApplicationOrigin($link);
    $this->flushHeaders()->get($link)->assertOk()->assertSee('password');
})->with('request origins');

test('a staff password reset link always uses the application host and still opens', function (Closure $root, array $headers): void {
    TrustProxies::at('*');
    Notification::fake();
    $this->seed(RolePermissionSeeder::class);
    $member = User::factory()->create()->assignRole('recruiter');

    $page = $this->withHeaders($headers)->get($root().route('filament.admin.auth.password-reset.request', absolute: false))->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn (string $encoded): string => html_entity_decode($encoded))
        ->first(fn (string $json): bool => json_decode($json, true)['memo']['name'] === StaffRequestPasswordReset::class);

    $this->withHeaders([...$headers, 'X-Livewire' => '1'])->postJson($root().EndpointResolver::updatePath(), ['components' => [[
        'snapshot' => $snapshot,
        'updates' => ['data.email' => $member->email],
        'calls' => [['path' => '', 'method' => 'request', 'params' => []]],
    ]]])->assertOk();

    $link = null;
    Notification::assertSentTo($member, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$link): bool {
        $link = $notification->url;

        return true;
    });

    expectApplicationOrigin($link);
    $this->flushHeaders()->get($link)->assertOk();
})->with('request origins');

test('with APP_TRUSTED_HOSTS set, only the listed host names are served', function (): void {
    config(['app.trusted_hosts' => ['hr.example.test', 'localhost']]);
    $this->app['env'] = 'production';

    $this->get('http://evil.example/portal/acme/login')->assertBadRequest();
    $this->get('http://hr.example.test.evil.example/portal/acme/login')->assertBadRequest();
    $this->get('http://hr.example.test/portal/acme/login')->assertOk();
    $this->get('http://localhost/up')->assertOk();
});

test('without APP_TRUSTED_HOSTS every host is served, as before', function (): void {
    config(['app.trusted_hosts' => []]);
    $this->app['env'] = 'production';

    $this->get('http://evil.example/portal/acme/login')->assertOk();
});
