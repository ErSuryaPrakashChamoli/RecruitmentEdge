<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Identity\OwnershipHandoffService;
use App\Services\PlatformAlertService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8.4: hands off a departed person's current work (OwnershipHandoffService) on the
 * automation queue. Ids only; unique per loss of access and idempotent (the service returns the
 * existing handoff on a retry), and it only ever pauses and reassigns — never restores authority.
 */
class ProcessOwnershipHandoffJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Phase 8.7 (D8.7-004): internal writes back off, never retry immediately.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    /**
     * SaaS-7 (S7-07): the tenant the loss of access happened in. A person who belongs to two
     * tenants can lose access in both in the same second; the unique lock is global, so its key
     * names the tenant or the second tenant's handoff would be dropped.
     */
    public readonly int $tenantId;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $employeeId,
        public readonly string $trigger,
        public readonly string $dedupeKey,
    ) {
        $this->tenantId = TenantContext::current()->requireId();
        $this->onQueue(config('automation.queue', 'automation'));
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:{$this->dedupeKey}";
    }

    public function handle(OwnershipHandoffService $handoffs): void
    {
        $handoffs->handOff($this->userId, $this->employeeId, $this->trigger, $this->dedupeKey);
    }

    /**
     * Phase 8.7 (D8.7-013/023): a handoff that could not finish leaves a departed person's rules or
     * open work unassigned — recorded, and raised to the platform administrators to finish from
     * Access Review.
     */
    public function failed(?Throwable $exception): void
    {
        // SaaS-7 (S7-02): refused only because the tenant is paused — leave the work as it is, so
        // PausedTenantWork can run it again when the tenant is usable (D-S3-15).
        if ($exception instanceof TenantUnavailable) {
            return;
        }

        Log::error('identity.handoff_failed', ['user_id' => $this->userId, 'trigger' => $this->trigger, 'error' => $exception !== null ? $exception::class : null]);

        if ($user = User::query()->find($this->userId)) {
            AuditLog::record($user, 'ownership_handoff_failed', null, ['trigger' => $this->trigger, 'error' => $exception !== null ? $exception::class : null]);
        }

        app(PlatformAlertService::class)->raise(
            "handoff-failed:{$this->userId}",
            'Ownership handoff failed',
            'The work handoff for a person who lost access did not finish after retries. Complete it from Access Review.',
        );
    }
}
