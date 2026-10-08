<?php

namespace App\Notifications;

use Filament\Notifications\DatabaseNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8.7 (D8.7-001/003/004/017): the in-app staff alert, queued on the dedicated
 * `notifications` queue with an encrypted payload (alert text names candidates and requisitions),
 * three tries with backoff, and a failure that is logged without its content.
 */
class StaffDatabaseNotification extends DatabaseNotification implements ShouldBeEncrypted
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->onQueue('notifications');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('queue.notification_failed', ['notification' => static::class, 'error' => $exception !== null ? $exception::class : null]);
    }
}
