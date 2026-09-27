<?php

namespace App\Jobs;

use App\Enums\AutomationExecutionStatus;
use App\Logging\SensitiveDataRedactor;
use App\Models\AutomationExecution;
use App\Services\Automation\AutomationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one automation execution on the automation queue (Phase 6). Unique per execution, and the
 * engine itself only claims a Pending execution under a row lock, so a duplicate dispatch (event
 * + process command) never runs the actions twice. Action failures are recorded per action, not
 * thrown; only an unexpected error reaches failed(), which marks the execution Failed for retry.
 */
class RunAutomationExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Phase 8.3: the uniqueness lock expires, so a lost job (worker killed, queue row removed) can
     * never permanently swallow a later dispatch or a manual retry of the same execution. It matches
     * the stale-run window (automation.stale_running_minutes, 60): after that the run is treated as
     * interrupted anyway. A duplicate delivery is still harmless — the engine only claims a Pending
     * execution under a row lock.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $executionId)
    {
        $this->onQueue(config('automation.queue', 'automation'));
    }

    public function uniqueId(): string
    {
        return (string) $this->executionId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AutomationEngine $engine): void
    {
        $engine->run($this->executionId);
    }

    public function failed(?Throwable $exception): void
    {
        AutomationExecution::query()
            ->whereKey($this->executionId)
            ->whereIn('status', [AutomationExecutionStatus::Pending, AutomationExecutionStatus::Running])
            ->update(['status' => AutomationExecutionStatus::Failed, 'failure_reason' => 'The automation worker failed: '.mb_substr(SensitiveDataRedactor::text((string) $exception?->getMessage()), 0, 300), 'completed_at' => now()]);
    }
}
