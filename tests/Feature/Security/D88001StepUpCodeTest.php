<?php

use App\Logging\SensitiveDataRedactor;
use App\Mail\CandidateStepUpCode;
use App\Models\AuditLog;
use App\Models\CandidatePortalAccount;
use App\Services\CandidateStepUpService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/D88001Helpers.php';

/**
 * D8.8-001 email one-time-code step-up: email only, 10 minutes, 5 attempts, a new code cancels the
 * previous one, single use, never stored or logged in plaintext, rate-limited, candidate- and
 * purpose-scoped, and its result lives only in the server-side session.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Mail::fake();
    Http::fake();
    $this->account = d88Account();
    $this->stepUp = app(CandidateStepUpService::class);
    $this->session = app('session')->driver();
    $this->session->start();
});

function d88IssuedCode(CandidatePortalAccount $account): string
{
    $code = null;
    Mail::assertQueued(CandidateStepUpCode::class, function (CandidateStepUpCode $mail) use ($account, &$code): bool {
        $code = $mail->code;

        return $mail->hasTo($account->email);
    });

    return (string) $code;
}

function d88LastCode(): string
{
    return (string) Mail::queued(CandidateStepUpCode::class)->last()->code;
}

test('a code is emailed only, to the portal email, on the encrypted notifications queue', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');

    $code = d88IssuedCode($this->account);

    expect($code)->toMatch('/^\d{6}$/')
        ->and(Mail::queued(CandidateStepUpCode::class)->sole()->queue)->toBe('notifications')
        ->and(new CandidateStepUpCode('x', '000000', 10))->toBeInstanceOf(ShouldBeEncrypted::class);
    Http::assertNothingSent();
});

test('the code is never stored or audited in plaintext', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');
    $code = d88IssuedCode($this->account);

    $cached = json_encode(Cache::get("portal:step-up:{$this->account->id}"));
    $audit = json_encode(AuditLog::query()->get()->map->only(['changes', 'old_values', 'reason'])->all());
    $cacheTable = Schema::hasTable('cache') ? json_encode(DB::table('cache')->get()) : '';

    expect(str_contains($cached, $code))->toBeFalse('the cache holds the plaintext code')
        ->and(str_contains($audit, $code))->toBeFalse('the audit trail holds the plaintext code')
        ->and(str_contains($cacheTable, $code))->toBeFalse()
        ->and(SensitiveDataRedactor::text("Your verification code is {$code}"))->not->toContain($code);
});

test('the right code verifies once, then is consumed', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');
    $code = d88IssuedCode($this->account);

    expect($this->stepUp->verify($this->account, 'sensitive_action', $code, $this->session))->toBeTrue()
        ->and($this->stepUp->isSatisfied($this->account, 'sensitive_action', $this->session))->toBeTrue()
        ->and($this->stepUp->verify($this->account, 'sensitive_action', $code, $this->session))->toBeFalse();
});

test('a code expires after 10 minutes', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');
    $code = d88IssuedCode($this->account);

    $this->travel(10)->minutes();
    $this->travel(1)->seconds();

    expect($this->stepUp->verify($this->account, 'sensitive_action', $code, $this->session))->toBeFalse();
});

test('five wrong attempts destroy the code, so even the right code then fails', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');
    $code = d88IssuedCode($this->account);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        expect($this->stepUp->verify($this->account, 'sensitive_action', $wrong, $this->session))->toBeFalse();
    }

    expect($this->stepUp->verify($this->account, 'sensitive_action', $code, $this->session))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'portal_step_up_failed')->where('changes->outcome', 'attempts_exhausted')->exists())->toBeTrue();
});

test('four wrong attempts still leave the right code usable', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');
    $code = d88IssuedCode($this->account);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $attempt) {
        $this->stepUp->verify($this->account, 'sensitive_action', $wrong, $this->session);
    }

    expect($this->stepUp->verify($this->account, 'sensitive_action', $code, $this->session))->toBeTrue();
});

test('issuing a new code cancels the previous one', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');
    $first = d88LastCode();
    $this->stepUp->issue($this->account, 'sensitive_action');
    $second = d88LastCode();

    expect($first === $second || ! $this->stepUp->verify($this->account, 'sensitive_action', $first, $this->session))->toBeTrue()
        ->and($this->stepUp->verify($this->account, 'sensitive_action', $second, $this->session))->toBeTrue();
});

test('a code works only for its own candidate and purpose', function (): void {
    config(['portal.step_up.purposes' => ['sensitive_action', 'other_action']]);
    $other = d88Account();
    $this->stepUp->issue($this->account, 'sensitive_action');
    $code = d88IssuedCode($this->account);

    expect($this->stepUp->verify($other, 'sensitive_action', $code, $this->session))->toBeFalse()
        ->and($this->stepUp->verify($this->account, 'other_action', $code, $this->session))->toBeFalse()
        ->and($this->stepUp->verify($this->account, 'sensitive_action', $code, $this->session))->toBeTrue()
        ->and($this->stepUp->isSatisfied($other, 'sensitive_action', $this->session))->toBeFalse()
        ->and($this->stepUp->isSatisfied($this->account, 'other_action', $this->session))->toBeFalse();
});

test('issuing is rate-limited per candidate', function (): void {
    foreach (range(1, 3) as $n) {
        $this->stepUp->issue($this->account, 'sensitive_action');
    }

    expect(fn () => $this->stepUp->issue($this->account, 'sensitive_action'))->toThrow(DomainException::class, 'Too many codes');
    $this->stepUp->issue(d88Account(), 'sensitive_action');
    Mail::assertQueued(CandidateStepUpCode::class, 4);
});

test('a verified step-up goes stale after its window', function (): void {
    $this->stepUp->issue($this->account, 'sensitive_action');
    $this->stepUp->verify($this->account, 'sensitive_action', d88IssuedCode($this->account), $this->session);

    $this->travel(16)->minutes();

    expect($this->stepUp->isSatisfied($this->account, 'sensitive_action', $this->session))->toBeFalse()
        ->and($this->stepUp->isSatisfied($this->account, 'sensitive_action', $this->session, 30))->toBeTrue();
});

describe('over HTTP', function (): void {
    beforeEach(function (): void {
        Route::middleware(['web', 'auth:candidate', 'candidate.step-up:sensitive_action'])
            ->get('portal/_step-up-probe', fn () => 'sensitive content')->name('portal.step-up-probe');
    });

    test('a route that requires step-up sends the candidate to verify, then back', function (): void {
        $this->actingAs($this->account, 'candidate');

        $this->get('/portal/_step-up-probe')->assertRedirect(route('portal.step-up.show'));
        $this->post(route('portal.step-up.send'))->assertSessionHasNoErrors();
        $sessionBefore = session()->getId();

        $this->post(route('portal.step-up.verify'), ['code' => d88IssuedCode($this->account)])->assertRedirect(url('/portal/_step-up-probe'));

        expect(session()->getId())->not->toBe($sessionBefore);
        $this->get('/portal/_step-up-probe')->assertOk()->assertSee('sensitive content');
    });

    test('browser-supplied step-up flags are ignored; only the server-side session counts', function (): void {
        $this->actingAs($this->account, 'candidate');

        $this->get('/portal/_step-up-probe?step_up=1&verified=1', ['X-Step-Up' => 'verified'])->assertRedirect(route('portal.step-up.show'));
        $this->withSession([CandidateStepUpService::SESSION_KEY => ['account' => d88Account()->id, 'purpose' => 'sensitive_action', 'verified_at' => now()->getTimestamp()]])
            ->get('/portal/_step-up-probe')->assertRedirect(route('portal.step-up.show'));
    });

    test('a wrong code gives the same generic error, and a malformed one is rejected', function (): void {
        $this->actingAs($this->account, 'candidate');
        $this->post(route('portal.step-up.send'));
        $code = d88IssuedCode($this->account);

        $this->post(route('portal.step-up.verify'), ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors(['code' => 'That code is not valid or has expired. Request a new code if needed.']);
        $this->post(route('portal.step-up.verify'), ['code' => '12ab'])->assertSessionHasErrors('code');
    });

    test('step-up pages are for signed-in candidates only — not guests, not staff', function (): void {
        $this->get(route('portal.step-up.show'))->assertRedirect(route('portal.login'));
        $this->actingAs(d88Staff(), 'web');
        $this->post(route('portal.step-up.send'))->assertRedirect(route('portal.login'));
        Mail::assertNothingQueued();
    });
});
