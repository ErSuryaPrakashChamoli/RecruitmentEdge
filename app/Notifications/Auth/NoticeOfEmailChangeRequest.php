<?php

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\NoticeOfEmailChangeRequest as FilamentNotice;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/**
 * Phase 8.7 (D8.7-001/017, SEC-87-02): the email-change notice carries the new address and a
 * signed block URL — encrypted in the queue payload, on the `notifications` queue.
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
        $this->onQueue('notifications');
    }
}
