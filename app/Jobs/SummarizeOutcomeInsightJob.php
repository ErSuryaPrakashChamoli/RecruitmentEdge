<?php

namespace App\Jobs;

use App\Enums\Entitlement;
use App\Enums\IntelligenceAiStatus;
use App\Jobs\Concerns\RunsForRequester;
use App\Models\OutcomeInsight;
use App\Models\User;
use App\Services\Entitlements\SkipWithoutEntitlement;
use App\Services\Intelligence\IntelligenceAiService;
use App\Services\Tenancy\TenantUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Writes an AI narrative of an Outcome Loop insight on the intelligence queue (Phase 8.2).
 */
class SummarizeOutcomeInsightJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsForRequester, SerializesModels;

    public int $tries = 2;

    /**
     * A lost job must not hold its uniqueness lock forever and silently swallow later requests.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $insightId, public readonly ?int $userId = null)
    {
        $this->onQueue(config('outcomes.queue', 'intelligence'));
    }

    public function uniqueId(): string
    {
        return (string) $this->insightId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60];
    }

    /**
     * SaaS-3: AI work runs only while the tenant's plan includes the AI assistant.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SkipWithoutEntitlement(Entitlement::AiAssistant)];
    }

    public function handle(IntelligenceAiService $ai): void
    {
        $insight = OutcomeInsight::query()->find($this->insightId);

        if ($insight !== null) {
            // Phase 8.7: re-checks the requester, runs as `ai` for them, and lets an unreachable
            // provider be retried (D8.7-004/015/016).
            $this->runForRequester($this->userId, $insight, fn (?User $requester) => $ai->summarizeInsight($insight, $requester, retryable: $this->attempts() < $this->tries));
        }
    }

    public function failed(?Throwable $exception): void
    {
        // SaaS-7 (S7-02): refused only because the tenant is paused — leave the work as it is, so
        // PausedTenantWork can run it again when the tenant is usable (D-S3-15).
        if ($exception instanceof TenantUnavailable) {
            return;
        }

        OutcomeInsight::query()->whereKey($this->insightId)->update(['ai_status' => IntelligenceAiStatus::Failed]);

        if (($subject = OutcomeInsight::query()->find($this->insightId)) !== null) {
            $this->recordAiFailure($subject, 'outcome_insight_ai_failed');
        }
    }
}
