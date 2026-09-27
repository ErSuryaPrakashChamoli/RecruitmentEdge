<?php

namespace App\Jobs;

use App\Enums\DistributionStatus;
use App\Enums\JobPostingStatus;
use App\Enums\RequisitionStatus;
use App\Models\JobDistribution;
use App\Services\Distribution\JobBoardRegistry;
use App\Services\Distribution\JobDistributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Runs one connector operation for one distribution (Phase 5). Temporary connector failures retry
 * with backoff; permanent ones are recorded as Failed with the connector's message.
 *
 * Phase 8.7 (D8.7-005): one job per distribution and operation waits in the queue at a time, jobs
 * for the same distribution never run together, and the operation is re-checked against the
 * posting as it is now — a publish that runs after the posting was paused or closed, or after the
 * channel is already live, does nothing rather than list the job twice.
 */
class PublishJobDistributionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /**
     * @param  string  $operation  publish | update | unpublish | pause
     */
    public function __construct(public readonly int $distributionId, public readonly string $operation)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return "{$this->distributionId}:{$this->operation}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("job-distribution:{$this->distributionId}"))->releaseAfter(30)->expireAfter(300)];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(JobBoardRegistry $boards, JobDistributionService $distribution): void
    {
        $row = JobDistribution::query()->with('posting.requisition')->find($this->distributionId);
        $connector = $row !== null ? $boards->find($row->channel) : null;

        if ($row === null || $connector === null || $this->isStale($row)) {
            return;
        }

        $result = match ($this->operation) {
            'unpublish', 'pause' => $connector->unpublish($row),
            'update' => $connector->update($row->posting, $row),
            default => $connector->publish($row->posting),
        };

        if (! $result->ok && $result->retryable && $this->attempts() < $this->tries) {
            throw new RuntimeException("Temporary {$row->channel} failure: {$result->error}");
        }

        $distribution->record($row, $this->operation, $result);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Job distribution gave up', ['distribution_id' => $this->distributionId, 'operation' => $this->operation, 'error' => $exception !== null ? $exception::class : null]);

        if (($row = JobDistribution::query()->find($this->distributionId)) !== null && $row->status === DistributionStatus::Pending) {
            $row->forceFill(['status' => DistributionStatus::Failed, 'last_error' => 'The job board could not be reached after several attempts.'])->save();
        }
    }

    private function isStale(JobDistribution $row): bool
    {
        $live = $row->posting?->status === JobPostingStatus::Published && $row->posting->requisition?->status === RequisitionStatus::Open;

        return match ($this->operation) {
            'publish' => ! $live || ($row->status === DistributionStatus::Published && $row->external_id !== null),
            'update' => ! $live || $row->status !== DistributionStatus::Published,
            default => $row->status === DistributionStatus::Unpublished,
        };
    }
}
