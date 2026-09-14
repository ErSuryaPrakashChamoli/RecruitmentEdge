<?php

namespace App\Services\AI\Gateway;

/**
 * Converts token usage into a USD cost from config('ai.pricing') (rates per 1M tokens). Returns
 * null — never a guessed zero — when the model has no pricing entry or no tokens were reported, so
 * "unknown cost" and "free" stay distinguishable on the AI Usage screen.
 */
class UsageCostCalculator
{
    /**
     * @param  array{input_tokens?: int|null, output_tokens?: int|null, cached_tokens?: int|null}  $usage
     */
    public function costFor(string $model, array $usage): ?float
    {
        // Read the whole map, not config("ai.pricing.{$model}") — model ids contain dots
        // (gemini-3.6-flash) that config()'s dot-notation would split into nested keys.
        $pricing = (config('ai.pricing') ?? [])[$model] ?? null;

        if (! is_array($pricing) || ! isset($pricing['input'], $pricing['output'])) {
            return null;
        }

        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cached = min((int) ($usage['cached_tokens'] ?? 0), $input);

        if ($input === 0 && $output === 0) {
            return null;
        }

        $cachedRate = (float) ($pricing['cached_input'] ?? $pricing['input']);

        $cost = (($input - $cached) * (float) $pricing['input'])
            + ($cached * $cachedRate)
            + ($output * (float) $pricing['output']);

        return round($cost / 1_000_000, 6);
    }
}
