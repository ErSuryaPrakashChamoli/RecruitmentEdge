<?php

namespace App\Services\AI\Privacy;

/**
 * Pattern-based defence in depth for provider-bound text (Phase 8.1): email addresses, phone
 * numbers, Indian PAN / Aadhaar numbers and currency amounts. Names cannot be recognised by a
 * pattern — they are kept out by AiProjector and by the per-request AiSensitiveValues registry.
 */
class PiiPatternScrubber
{
    /**
     * kind => [pattern, replacement]. Order matters: emails before phone numbers.
     */
    private const array PATTERNS = [
        'email' => ['/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email removed]'],
        'phone' => ['/(?<![\w\-])(?:\+?\d{1,3}[\s\-]?)?(?:\(?\d{2,5}\)?[\s\-]?)?[6-9]\d{4}[\s\-]?\d{5}(?![\w\-])/', '[phone removed]'],
        'pan' => ['/\b[A-Z]{5}\d{4}[A-Z]\b/', '[id removed]'],
        'aadhaar' => ['/(?<![\w\-])[2-9]\d{3}[\s\-]?\d{4}[\s\-]?\d{4}(?![\w\-])/', '[id removed]'],
        'amount' => ['/(?:₹|\bRs\.?|\bINR)\s?\d[\d,]*(?:\.\d+)?(?:\s?(?:lakhs?|lacs?|crores?|lpa|k))?|\b\d+(?:\.\d+)?\s?(?:lpa|lakhs?|lacs?|crores?)\b/iu', '[amount removed]'],
    ];

    /**
     * @return array{text: string, counts: array<string, int>}
     */
    public function scrub(string $text): array
    {
        $counts = [];

        foreach (self::PATTERNS as $kind => [$pattern, $replacement]) {
            $text = (string) preg_replace($pattern, $replacement, $text, -1, $count);

            if ($count > 0) {
                $counts[$kind] = ($counts[$kind] ?? 0) + $count;
            }
        }

        return ['text' => $text, 'counts' => $counts];
    }
}
