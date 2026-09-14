<?php

namespace App\Services\AI\Gateway;

use InvalidArgumentException;

/**
 * Resolves a model id from a semantic category (classification, extraction, summarization,
 * generation, balanced, advanced, planning) via config/ai.php — callers never hard-code a model
 * id, so rolling a new model out is a config/env change, not a code change.
 *
 * Embeddings and web search have their own config entries (ai.embeddings.model /
 * ai.web_search.model) because AI_EMBEDDING_PROVIDER / AI_WEB_SEARCH_PROVIDER may name a different
 * vendor than AI_PROVIDER — reusing a chat model id there would send the wrong vendor's id.
 */
class ModelRouter
{
    public function forCategory(string $category): string
    {
        return $this->resolve("ai.models.{$category}", "AI category [{$category}]");
    }

    public function forEmbeddings(): string
    {
        return $this->resolve('ai.embeddings.model', 'embeddings');
    }

    public function forWebSearch(): string
    {
        return $this->resolve('ai.web_search.model', 'web search');
    }

    private function resolve(string $configKey, string $label): string
    {
        $model = config($configKey);

        if (blank($model)) {
            throw new InvalidArgumentException("No model is configured for {$label}.");
        }

        return $model;
    }
}
