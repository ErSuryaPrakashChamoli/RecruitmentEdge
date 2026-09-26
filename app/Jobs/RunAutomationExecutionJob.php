<?php

namespace App\Jobs;

use App\Enums\AutomationExecutionStatus;
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
            ->update(['status' => AutomationExecutionStatus::Failed, 'failure_reason' => 'The automation worker failed: '.mb_substr((string) $exception?->getMessage(), 0, 300), 'completed_at' => now()]);
    }
}
