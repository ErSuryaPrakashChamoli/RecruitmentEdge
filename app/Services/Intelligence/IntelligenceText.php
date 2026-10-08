<?php

namespace App\Services\Intelligence;

use Illuminate\Support\Str;

/**
 * Text normalisation shared by EDGE Intelligence. Skills are compared as case-insensitive tags with
 * collapsed whitespace — literal matching, no synonyms (a documented limitation), so "unknown" is
 * reported rather than a guessed match.
 */
final class IntelligenceText
{
    public static function normalize(string $text): string
    {
        return Str::of($text)->lower()->squish()->toString();
    }

    public static function skillKey(string $skill): string
    {
        return 'skill:'.(Str::slug(self::normalize($skill)) ?: md5($skill));
    }

    public static function range(mixed $min, mixed $max, string $unit = ''): string
    {
        $format = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        $suffix = $unit !== '' ? " {$unit}" : '';

        return match (true) {
            $min !== null && $max !== null => $format($min).'–'.$format($max).$suffix,
            $min !== null => 'at least '.$format($min).$suffix,
            default => 'up to '.$format($max).$suffix,
        };
    }

    /**
     * Whether $haystack contains $needle as a whole phrase (case-insensitive).
     */
    public static function containsPhrase(?string $haystack, ?string $needle): bool
    {
        if (blank($haystack) || blank($needle)) {
            return false;
        }

        return preg_match('/(^|\W)'.preg_quote(self::normalize($needle), '/').'($|\W)/u', self::normalize($haystack)) === 1;
    }
}
