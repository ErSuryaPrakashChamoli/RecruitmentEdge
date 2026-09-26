<?php

namespace App\Jobs;

use App\Enums\IntelligenceAiStatus;
use App\Models\RoleDnaProfile;
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
 * Generates AI Role DNA suggestions on the intelligence queue (Phase 7) — never during a request.
 */
class GenerateRoleDnaSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    public function handle(IntelligenceAiService $ai): void
    {
        $profile = RoleDnaProfile::query()->find($this->profileId);

        if ($profile !== null) {
            $ai->generateRoleDnaSuggestions($profile, $this->userId !== null ? User::query()->find($this->userId) : null, retryable: $this->attempts() < $this->tries);
        }
    }

    public function failed(?Throwable $exception): void
    {
        RoleDnaProfile::query()->whereKey($this->profileId)->update(['ai_status' => IntelligenceAiStatus::Failed, 'ai_error' => 'The AI service was unavailable on every attempt — try again later.']);
    }
}
