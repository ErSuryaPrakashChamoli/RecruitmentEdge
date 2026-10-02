<?php

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\VerifyEmailChange as FilamentVerifyEmailChange;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/**
 * Phase 8.9 (P89-SEC-002, ED-06): Filament's email-change verification mail carries a signed
 * verification URL, so — like its siblings ResetPassword and NoticeOfEmailChangeRequest since Phase
 * 8.7 — its queued payload is encrypted (never plain text in `jobs` or `failed_jobs`) and it travels
 * on the `security` queue, never behind bulk work on `default`. Bound in place of Filament's class
 * in AppServiceProvider.
 */
class VerifyEmailChange extends FilamentVerifyEmailChange implements ShouldBeEncrypted
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public function __construct()
    {
        $this->onQueue('security');
    }
}
