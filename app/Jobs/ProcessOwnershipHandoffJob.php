<?php

namespace App\Jobs;

use App\Services\Identity\OwnershipHandoffService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.4: hands off a departed person's current work (OwnershipHandoffService) on the
 * automation queue. Ids only; unique per loss of access and idempotent (the service returns the
 * existing handoff on a retry), and it only ever pauses and reassigns — never restores authority.
 */
class ProcessOwnershipHandoffJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $employeeId,
        public readonly string $trigger,
        public readonly string $dedupeKey,
    ) {
        $this->onQueue(config('automation.queue', 'automation'));
    }

    public function uniqueId(): string
    {
        return $this->dedupeKey;
    }

    public function handle(OwnershipHandoffService $handoffs): void
    {
        $handoffs->handOff($this->userId, $this->employeeId, $this->trigger, $this->dedupeKey);
    }
}
