<?php

namespace App\Services\AI\Privacy;

/**
 * Request-scoped register of personal values that must not leave the application (Phase 8.1).
 *
 * Whenever AiProjector (or the conversation context) touches a person, it registers their name,
 * email and phone numbers here. AiPayloadSanitizer and AiEgressGuard then remove any occurrence
 * of those exact values from provider-bound payloads — a name that no pattern could catch is
 * still stopped if it slips into free text. Bound as `scoped`, so it resets per request / job.
 */
class AiSensitiveValues
{
    private const int MIN_LENGTH = 4;

    /**
     * @var array<string, string> lower-case value => kind
     */
    private array $values = [];

    public function add(?string $value, string $kind = 'name'): void
    {
        $value = trim((string) $value);

        if (mb_strlen($value) >= self::MIN_LENGTH) {
            $this->values[mb_strtolower($value)] = $kind;
        }
    }

    /**
     * @return array{text: string, counts: array<string, int>}
     */
    public function scrub(string $text): array
    {
        $counts = [];

        if ($this->values === [] || $text === '') {
            return ['text' => $text, 'counts' => $counts];
        }

        // Longest first, so "Alice Smith" is removed before a shorter registered value inside it.
        $values = $this->values;
        uksort($values, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($values as $value => $kind) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu';
            $text = (string) preg_replace($pattern, "[{$kind} removed]", $text, -1, $count);

            if ($count > 0) {
                $counts[$kind] = ($counts[$kind] ?? 0) + $count;
            }
        }

        return ['text' => $text, 'counts' => $counts];
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
