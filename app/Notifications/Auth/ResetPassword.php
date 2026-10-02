<?php

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/**
 * Phase 8.7 (D8.7-001/017, SEC-87-02): Filament's password-reset mail carries a bearer token URL, so
 * its queued payload is encrypted (never plain text in `jobs` or `failed_jobs`) and it travels on
 * the `security` queue (Phase 8.9, ED-05: its own worker). Bound in place of Filament's class in AppServiceProvider.
 */
class ResetPassword extends FilamentResetPassword implements ShouldBeEncrypted
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->onQueue('security');
    }
}
