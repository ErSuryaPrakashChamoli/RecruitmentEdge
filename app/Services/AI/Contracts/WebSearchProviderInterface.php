<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\WebSearchResult;
use App\Services\AI\Exceptions\AiProviderUnavailableException;

interface WebSearchProviderInterface
{
    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, WebSearchResult>
     *
     * @throws AiProviderUnavailableException when no credential is configured
     */
    public function search(string $query, array $options = []): array;
}
