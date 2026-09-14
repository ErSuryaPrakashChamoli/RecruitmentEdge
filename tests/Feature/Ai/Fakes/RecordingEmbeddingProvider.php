<?php

namespace Tests\Feature\Ai\Fakes;

use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\ReportsUsage;

/**
 * Records the model id each embed() call receives and reports a fixed token usage, so tests can
 * assert embedding routing and token logging.
 */
class RecordingEmbeddingProvider implements EmbeddingProviderInterface, ReportsUsage
{
    /**
     * @var array<int, string|null>
     */
    public array $models = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function embed(array $texts, ?string $model = null, string $context = 'document'): array
    {
        $this->models[] = $model;

        return array_map(fn () => [1.0], $texts);
    }

    public function lastUsage(): array
    {
        return ['input_tokens' => 42, 'output_tokens' => 0, 'cached_tokens' => 0];
    }
}
