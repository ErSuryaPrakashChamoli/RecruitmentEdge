<?php

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\NoticeOfEmailChangeRequest as FilamentNotice;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8.7 (D8.7-001/017, SEC-87-02): the email-change notice carries the new address and a
 * signed block URL — encrypted in the queue payload, on the `security` queue (Phase 8.9, ED-05: its own worker).
 */
class NoticeOfEmailChangeRequest extends FilamentNotice implements ShouldBeEncrypted
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public function __construct(string $newEmail, string $blockVerificationUrl)
    {
        parent::__construct($newEmail, $blockVerificationUrl);
        $this->onQueue('security');
    }

    /**
     * SaaS-7 (S7-09): retries exhausted — logged without the link or the address.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('queue.notification_failed', ['notification' => static::class, 'error' => $exception !== null ? $exception::class : null]);
    }
}
