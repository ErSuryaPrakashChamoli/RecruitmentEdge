<?php

namespace App\Services\AI\DTO;

use Carbon\CarbonImmutable;

/**
 * One external research result. Every field here is preserved through to the UI so the Copilot can
 * always answer "where did this come from?" (spec section 46).
 */
final class WebSearchResult
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $excerpt,
        public readonly ?string $sourceDate,
        public readonly CarbonImmutable $retrievedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'excerpt' => $this->excerpt,
            'source_date' => $this->sourceDate,
            'retrieved_at' => $this->retrievedAt->toIso8601String(),
        ];
    }
}
