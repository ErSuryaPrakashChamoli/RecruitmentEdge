<?php

namespace App\Services\AI\Privacy;

/**
 * Layer 2 of the Phase 8.1 privacy boundary. Cleans a tool result (or any provider-bound
 * structure) before it is persisted, replayed or sent: drops every AiFieldPolicy-prohibited key at
 * any depth, then scrubs registered personal values and PII patterns from every string. The
 * cleaned structure is what ActionExecutor stores, so persistence never holds more than the
 * provider is allowed to see.
 */
class AiPayloadSanitizer
{
    public function __construct(
        private readonly PiiPatternScrubber $patterns,
        private readonly AiSensitiveValues $sensitiveValues,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{payload: array<array-key, mixed>, counts: array<string, int>}
     */
    public function sanitize(array $payload): array
    {
        $counts = [];
        $clean = $this->clean($payload, $counts);

        return ['payload' => $clean, 'counts' => $counts];
    }

    /**
     * Scrubs string leaves only, keeping every key — for model-written tool-call arguments, which
     * originated at the provider (e.g. a `remarks` argument the model composed itself).
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{payload: array<array-key, mixed>, counts: array<string, int>}
     */
    public function sanitizeStrings(array $payload): array
    {
        $counts = [];
        $clean = $this->clean($payload, $counts, dropKeys: false);

        return ['payload' => $clean, 'counts' => $counts];
    }

    /**
     * Cleans stored JSON (e.g. a replayed tool message). Non-JSON text is scrubbed as text.
     *
     * @return array{text: string, counts: array<string, int>}
     */
    public function sanitizeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $this->sanitizeText($json);
        }

        ['payload' => $payload, 'counts' => $counts] = $this->sanitize($decoded);

        return ['text' => (string) json_encode($payload), 'counts' => $counts];
    }

    /**
     * @return array{text: string, counts: array<string, int>}
     */
    public function sanitizeText(string $text): array
    {
        $values = $this->sensitiveValues->scrub($text);
        $patterns = $this->patterns->scrub($values['text']);

        return ['text' => $patterns['text'], 'counts' => self::merge($values['counts'], $patterns['counts'])];
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $kind => $count) {
            $a[$kind] = ($a[$kind] ?? 0) + $count;
        }

        return $a;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function clean(mixed $value, array &$counts, bool $dropKeys = true): mixed
    {
        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                if ($dropKeys && AiFieldPolicy::isProhibitedKey($key)) {
                    $counts['field'] = ($counts['field'] ?? 0) + 1;

                    continue;
                }

                $clean[$key] = $this->clean($item, $counts, $dropKeys);
            }

            return $clean;
        }

        if (is_string($value)) {
            ['text' => $text, 'counts' => $found] = $this->sanitizeText($value);
            $counts = self::merge($counts, $found);

            return $text;
        }

        return $value;
    }
}
