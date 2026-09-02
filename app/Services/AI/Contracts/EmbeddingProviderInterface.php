<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Exceptions\AiProviderUnavailableException;

interface EmbeddingProviderInterface
{
    public function isConfigured(): bool;

    /**
     * @param  array<int, string>  $texts
     * @param  'document'|'query'  $context  hints providers that distinguish indexing text from a
     *                                       search query (e.g. Gemini's taskType); providers that
     *                                       don't make the distinction simply ignore it
     * @return array<int, array<int, float>>
     *
     * @throws AiProviderUnavailableException when no credential is configured
     */
    public function embed(array $texts, ?string $model = null, string $context = 'document'): array;
}
