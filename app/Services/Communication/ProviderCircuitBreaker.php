<?php

namespace App\Services\Communication;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.7 (D8.7-019 b): pauses a provider while it is down, so an outage does not burn every
 * message's retries and fail it. After `threshold` temporary failures within `window` seconds the
 * circuit opens for `cooldown` seconds: messages for that provider stay Queued (no attempt used)
 * and reliability:sweep re-queues them once the circuit has closed. Any accepted message
 * resets the count. State lives in the cache, shared by every worker.
 */
class ProviderCircuitBreaker
{
    public function isOpen(string $provider): bool
    {
        return $this->openUntil($provider) !== null;
    }

    public function openUntil(string $provider): ?int
    {
        $until = Cache::get($this->openKey($provider));

        return is_int($until) && $until > now()->getTimestamp() ? $until : null;
    }

    /**
     * Records a temporary failure. Returns true when this failure opened the circuit.
     */
    public function recordFailure(string $provider): bool
    {
        $failuresKey = $this->failuresKey($provider);
        Cache::add($failuresKey, 0, (int) config('communications.circuit.window', 300));
        $failures = (int) Cache::increment($failuresKey);

        if ($failures < (int) config('communications.circuit.threshold', 5)) {
            return false;
        }

        $cooldown = (int) config('communications.circuit.cooldown', 300);
        Cache::put($this->openKey($provider), now()->getTimestamp() + $cooldown, $cooldown);
        Cache::forget($failuresKey);
        Log::warning('communications.circuit_opened', ['provider' => $provider, 'cooldown_seconds' => $cooldown]);

        return true;
    }

    public function recordSuccess(string $provider): void
    {
        Cache::forget($this->failuresKey($provider));
    }

    public function reset(string $provider): void
    {
        Cache::forget($this->failuresKey($provider));
        Cache::forget($this->openKey($provider));
    }

    private function failuresKey(string $provider): string
    {
        return "communications:circuit:{$provider}:failures";
    }

    private function openKey(string $provider): string
    {
        return "communications:circuit:{$provider}:open-until";
    }
}
