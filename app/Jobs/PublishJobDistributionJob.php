<?php

namespace App\Jobs;

use App\Models\JobDistribution;
use App\Services\Distribution\JobBoardRegistry;
use App\Services\Distribution\JobDistributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Runs one connector operation for one distribution (Phase 5). Temporary connector failures retry
 * with backoff; permanent ones are recorded as Failed with the connector's message.
 */
class PublishJobDistributionJob implements ShouldQueue
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

        if ($row === null || $connector === null) {
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
        Log::warning('Job distribution gave up', ['distribution_id' => $this->distributionId, 'operation' => $this->operation, 'error' => $exception?->getMessage()]);
    }
}
