<?php

namespace App\Jobs;

use App\Enums\IntelligenceAiStatus;
use App\Models\HiringMemoryRecord;
use App\Models\User;
use App\Services\Intelligence\IntelligenceAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Writes an AI narrative of a Hiring Memory record's facts on the intelligence queue (Phase 7).
 */
class SummarizeHiringMemoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * A lost job must not hold its uniqueness lock forever and silently swallow later requests.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $recordId, public readonly ?int $userId = null)
    {
        $this->onQueue(config('intelligence.queue', 'intelligence'));
    }

    public function uniqueId(): string
    {
        return (string) $this->recordId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(IntelligenceAiService $ai): void
    {
        $record = HiringMemoryRecord::query()->find($this->recordId);

        if ($record !== null) {
            $ai->summarizeMemory($record, $this->userId !== null ? User::query()->find($this->userId) : null);
        }
    }

    public function failed(?Throwable $exception): void
    {
        HiringMemoryRecord::query()->whereKey($this->recordId)->update(['ai_status' => IntelligenceAiStatus::Failed]);
    }
}
