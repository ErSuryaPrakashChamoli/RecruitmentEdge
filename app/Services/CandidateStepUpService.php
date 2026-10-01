<?php

namespace App\Services;

use App\Mail\CandidateStepUpCode;
use App\Models\AuditLog;
use App\Models\CandidatePortalAccount;
use DomainException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 8.8 (D8.8-001): the candidate email one-time-code step-up — the only implementation of it.
 *
 * - One active code per portal account: issuing replaces (invalidates) the previous one.
 * - The code is 6 random digits (CSPRNG), emailed only, valid 10 minutes, 5 attempts.
 * - Only an HMAC of the code is kept, in the shared cache, bound to the account, the purpose and
 *   that issue's id — never the code itself, never in a log or audit row.
 * - A successful check consumes the code, rotates the session id and records the step-up in the
 *   server-side session for that account and purpose; nothing the browser sends can set it.
 * - Issuing is rate-limited per account; attempts are counted under a lock per account.
 */
class CandidateStepUpService
{
    public const string SESSION_KEY = 'portal.step_up';

    public function issue(CandidatePortalAccount $account, string $purpose): void
    {
        $this->ensurePurpose($purpose);

        $limiterKey = "portal-step-up-issue:{$account->getKey()}";

        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('portal.step_up.issue_limit'))) {
            AuditLog::asCandidate($account, fn () => AuditLog::record($account, 'portal_step_up_throttled', null, ['purpose' => $purpose]));

            throw new DomainException('Too many codes requested. Try again in '.max(1, (int) ceil(RateLimiter::availableIn($limiterKey) / 60)).' minute(s).');
        }

        RateLimiter::hit($limiterKey, (int) config('portal.step_up.issue_window_minutes') * 60);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $issueId = (string) Str::uuid();
        $ttlMinutes = (int) config('portal.step_up.code_ttl_minutes');

        Cache::lock($this->lockKey($account), 5)->block(3, fn () => Cache::put($this->codeKey($account), [
            'id' => $issueId,
            'purpose' => $purpose,
            'hash' => $this->hash($account, $purpose, $issueId, $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ], now()->addMinutes($ttlMinutes)));

        AuditLog::asCandidate($account, fn () => AuditLog::record($account, 'portal_step_up_issued', null, ['purpose' => $purpose, 'issue_id' => $issueId, 'channel' => 'email']));

        Mail::to($account->email)->send(new CandidateStepUpCode((string) $account->candidate?->full_name, $code, $ttlMinutes));
    }

    /**
     * True when $code is the account's active code for $purpose; the code is then consumed and the
     * step-up recorded in $session. Every other outcome counts as an attempt (or finds no code).
     */
    public function verify(CandidatePortalAccount $account, string $purpose, string $code, Session $session): bool
    {
        $this->ensurePurpose($purpose);

        [$verified, $outcome, $issueId] = Cache::lock($this->lockKey($account), 5)->block(3, function () use ($account, $purpose, $code): array {
            $entry = Cache::get($this->codeKey($account));

            if (! is_array($entry) || $entry['expires_at'] <= now()->getTimestamp()) {
                Cache::forget($this->codeKey($account));

                return [false, 'no_active_code', null];
            }

            if ($entry['purpose'] === $purpose && hash_equals($entry['hash'], $this->hash($account, $purpose, $entry['id'], $code))) {
                Cache::forget($this->codeKey($account));

                return [true, 'verified', $entry['id']];
            }

            $entry['attempts']++;

            if ($entry['attempts'] >= (int) config('portal.step_up.max_attempts')) {
                Cache::forget($this->codeKey($account));

                return [false, 'attempts_exhausted', $entry['id']];
            }

            Cache::put($this->codeKey($account), $entry, now()->setTimestamp($entry['expires_at']));

            return [false, 'wrong_code', $entry['id']];
        });

        AuditLog::asCandidate($account, fn () => AuditLog::record($account, $verified ? 'portal_step_up_verified' : 'portal_step_up_failed', null, array_filter(['purpose' => $purpose, 'issue_id' => $issueId, 'outcome' => $outcome])));

        if ($verified) {
            $session->migrate(true);
            $session->put(self::SESSION_KEY, ['account' => $account->getKey(), 'purpose' => $purpose, 'verified_at' => now()->getTimestamp()]);
        }

        return $verified;
    }

    /**
     * Whether this session completed a step-up for this account and purpose within the window.
     */
    public function isSatisfied(CandidatePortalAccount $account, string $purpose, Session $session, ?int $freshForMinutes = null): bool
    {
        $state = $session->get(self::SESSION_KEY);
        $window = ($freshForMinutes ?? (int) config('portal.step_up.fresh_for_minutes')) * 60;

        return is_array($state)
            && ($state['account'] ?? null) === $account->getKey()
            && ($state['purpose'] ?? null) === $purpose
            && is_int($state['verified_at'] ?? null)
            && $state['verified_at'] >= now()->getTimestamp() - $window;
    }

    private function ensurePurpose(string $purpose): void
    {
        if (! in_array($purpose, (array) config('portal.step_up.purposes'), true)) {
            throw new InvalidArgumentException("Unknown step-up purpose [{$purpose}].");
        }
    }

    private function hash(CandidatePortalAccount $account, string $purpose, string $issueId, string $code): string
    {
        return hash_hmac('sha256', implode('|', ['candidate-step-up', $account->getKey(), $account->public_id, $purpose, $issueId, $code]), (string) config('app.key'));
    }

    private function codeKey(CandidatePortalAccount $account): string
    {
        return "portal:step-up:{$account->getKey()}";
    }

    private function lockKey(CandidatePortalAccount $account): string
    {
        return "portal:step-up-lock:{$account->getKey()}";
    }
}
