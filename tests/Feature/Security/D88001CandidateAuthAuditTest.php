<?php

use App\Models\AuditLog;
use App\Services\CandidatePortalService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Timebox;

require_once __DIR__.'/D88001Helpers.php';

/**
 * D8.8-001: candidate authentication events are audited with the candidate, the outcome and the
 * correlation id — and never a password, code, token or signed-link secret.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->seed(RolePermissionSeeder::class);
});

test('sign-in, failed sign-in, logout and password set are audited without secrets', function (): void {
    $account = d88Account();
    $link = app(CandidatePortalService::class)->passwordLink($account);

    $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'Wrong#Password1']);
    $this->withHeader('X-Request-Id', 'corr-login-12345')->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'Secret#12345']);
    $this->post(route('portal.logout'));
    $this->post($link, ['password' => 'NewSecret#12345', 'password_confirmation' => 'NewSecret#12345']);

    $rows = AuditLog::query()->where('auditable_id', $account->id)->orderBy('id')->get();
    $actions = $rows->pluck('action')->all();
    $stored = json_encode($rows->map->only(['changes', 'old_values', 'reason', 'action'])->all());

    expect($actions)->toContain('portal_login_failed', 'portal_login', 'portal_logout', 'portal_password_set')
        ->and($rows->firstWhere('action', 'portal_login_failed')->actor_kind)->toBe('system')
        ->and($rows->firstWhere('action', 'portal_login')->actor_kind)->toBe('candidate')
        ->and($rows->firstWhere('action', 'portal_login')->request_id)->not->toBeNull()
        ->and($rows->firstWhere('action', 'portal_logout')->actor_kind)->toBe('candidate')
        ->and($rows->firstWhere('action', 'portal_password_set')->actor_kind)->toBe('candidate')
        ->and($rows->pluck('user_id')->filter()->all())->toBe([]);

    foreach (['Wrong#Password1', 'Secret#12345', 'NewSecret#12345', $account->email, 'signature=', '&k=', parse_url($link, PHP_URL_QUERY)] as $secret) {
        expect(str_contains($stored, (string) $secret))->toBeFalse("audit contains {$secret}");
    }
});

test('a failed sign-in for an unknown email is not recorded against anyone and gets the same answer', function (): void {
    $known = d88Account();

    $unknown = $this->post(route('portal.login.store'), ['email' => 'nobody@example.test', 'password' => 'Whatever#123']);
    $wrong = $this->post(route('portal.login.store'), ['email' => $known->email, 'password' => 'Whatever#123']);

    expect(AuditLog::query()->where('action', 'portal_login_failed')->count())->toBe(1);
    $unknown->assertSessionHasErrors(['email' => 'These details do not match our records.']);
    $wrong->assertSessionHasErrors(['email' => 'These details do not match our records.']);
});

test('the failed sign-in audit runs in the same fixed time box for a known and an unknown email', function (): void {
    $known = d88Account();
    $boxes = new ArrayObject;
    app()->instance(Timebox::class, new class($boxes) extends Timebox
    {
        public function __construct(private ArrayObject $boxes) {}

        public function call(callable $callback, int $microseconds): mixed
        {
            $this->boxes[] = ['microseconds' => $microseconds, 'failedRowsBefore' => AuditLog::query()->where('action', 'portal_login_failed')->count()];
            $result = $callback($this);
            $this->boxes[count($this->boxes) - 1]['failedRowsAfter'] = AuditLog::query()->where('action', 'portal_login_failed')->count();

            return $result;
        }
    });

    $this->post(route('portal.login.store'), ['email' => $known->email, 'password' => 'Whatever#123']);
    $this->post(route('portal.login.store'), ['email' => 'nobody@example.test', 'password' => 'Whatever#123']);
    [$knownBox, $unknownBox] = $boxes->getArrayCopy();
    $row = AuditLog::query()->where('action', 'portal_login_failed')->sole();

    expect($boxes)->toHaveCount(2)
        ->and($knownBox['microseconds'])->toBe($unknownBox['microseconds'])
        ->and($knownBox['microseconds'])->toBeGreaterThanOrEqual(10_000)
        ->and([$knownBox['failedRowsBefore'], $knownBox['failedRowsAfter']])->toBe([0, 1])
        ->and([$unknownBox['failedRowsBefore'], $unknownBox['failedRowsAfter']])->toBe([1, 1])
        ->and($row->auditable_id)->toBe($known->id)
        ->and($row->actor_kind)->toBe('system')
        ->and($row->request_id)->not->toBeNull()
        ->and($row->ip_address)->not->toBeNull();
});
