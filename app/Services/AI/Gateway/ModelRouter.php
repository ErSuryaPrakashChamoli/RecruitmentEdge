<?php

namespace App\Services\AI\Gateway;

use InvalidArgumentException;

/**
 * Resolves a model id from a semantic category (classification, extraction, summarization,
 * generation, balanced, advanced, planning) via config/ai.php — callers never hard-code a model
 * id, so rolling a new model out is a config/env change, not a code change.
 */
class ModelRouter
{
    public function forCategory(string $category): string
    {
        $model = config("ai.models.{$category}");

        if (blank($model)) {
            throw new InvalidArgumentException("No model is configured for AI category [{$category}].");
        }

        return $model;
    }
}
