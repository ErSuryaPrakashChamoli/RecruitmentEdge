<?php

namespace App\Services\AI\Privacy;

use Illuminate\Support\Str;

/**
 * AI and retrieved text rendered as Markdown. Raw HTML is stripped and unsafe links are refused, as
 * before. Phase 8.10 (P810-AI-03): images are rendered as their alt text. An image loads by itself,
 * so a prompt-injected `![](https://…?CAND-…)` would otherwise send a reference — and the name it
 * resolves to for the viewer — to another site without a click.
 */
class AiMarkdown
{
    public static function render(?string $text): string
    {
        $html = Str::markdown((string) $text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            fn (array $image): string => preg_match('/\balt="([^"]*)"/i', $image[0], $alt) === 1 ? $alt[1] : '',
            $html,
        );
    }
}
