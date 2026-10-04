<?php

namespace App\Jobs;

use App\Enums\Entitlement;
use App\Enums\IntelligenceAiStatus;
use App\Jobs\Concerns\RunsForRequester;
use App\Models\RoleDnaProfile;
use App\Models\User;
use App\Services\Entitlements\SkipWithoutEntitlement;
use App\Services\Intelligence\IntelligenceAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Generates AI Role DNA suggestions on the intelligence queue (Phase 7) — never during a request.
 */
class GenerateRoleDnaSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsForRequester, SerializesModels;

    public int $tries = 2;

    /**
     * A lost job must not hold its uniqueness lock forever and silently swallow later requests.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $profileId, public readonly ?int $userId = null)
    {
        $this->onQueue(config('intelligence.queue', 'intelligence'));
    }

    public function uniqueId(): string
    {
        return (string) $this->profileId;
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
        $profile = RoleDnaProfile::query()->find($this->profileId);

        if ($profile !== null) {
            // Phase 8.7: re-checks the requester and runs as `ai` for them (D8.7-015/016).
            $this->runForRequester($this->userId, $profile, fn (?User $requester) => $ai->generateRoleDnaSuggestions($profile, $requester, retryable: $this->attempts() < $this->tries));
        }
    }

    public function failed(?Throwable $exception): void
    {
        RoleDnaProfile::query()->whereKey($this->profileId)->update(['ai_status' => IntelligenceAiStatus::Failed, 'ai_error' => 'The AI service was unavailable on every attempt — try again later.']);
    }
}
