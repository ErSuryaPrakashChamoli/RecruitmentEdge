<?php

namespace Tests\Feature\Ai\Fakes;

use App\Services\AI\Contracts\WebSearchProviderInterface;

/**
 * Records the options (notably the routed model id) each web search receives and returns no results.
 */
class RecordingWebSearchProvider implements WebSearchProviderInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $options = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function search(string $query, array $options = []): array
    {
        $this->options[] = $options;

        return [];
    }
}
