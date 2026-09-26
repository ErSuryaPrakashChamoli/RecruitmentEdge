<?php

namespace App\Services;

/**
 * Deterministic normalisation of candidate identifiers for duplicate detection. Pure functions
 * (no framework dependency) so the same rules run in the model's saving hook, the backfill
 * migration and the detector, and can be swapped for locale-aware rules later without touching
 * callers. Future AI duplicate intelligence (Phase 7) sits on top of these, never instead of them.
 */
class CandidateIdentityNormalizer
{
    /**
     * Digits only, reduced to the 10-digit national number when a country code (+91 / 0091) or
     * trunk prefix (0) is present — so "+91 98765-43210", "098765 43210" and "9876543210" match.
     */
    public static function mobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        if ($digits === '') {
            return null;
        }

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /**
     * Lower-cased and trimmed, with plus-addressing tags removed ("a+jobs@x.com" → "a@x.com") and
     * Gmail's ignored dots dropped, since those all deliver to the same inbox.
     */
    public static function email(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || ! str_contains($email, '@')) {
            return $email === '' ? null : $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return "{$local}@{$domain}";
    }

    /**
     * Letters only, lower-cased, with name parts sorted — "Sharma, Rahul" and "rahul  sharma" match.
     */
    public static function name(?string $name): ?string
    {
        $parts = preg_split('/\s+/', trim(preg_replace('/[^\pL\s]+/u', ' ', mb_strtolower((string) $name)) ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return null;
        }

        sort($parts);

        return implode(' ', $parts);
    }

    /**
     * Masks all but the last 3 digits ("XXXXXXX210") for showing a possible duplicate to a user who
     * may not be allowed to see that candidate.
     */
    public static function maskMobile(?string $mobile): string
    {
        $digits = self::mobile($mobile) ?? '';

        return $digits === '' ? '—' : str_repeat('X', max(0, strlen($digits) - 3)).substr($digits, -3);
    }

    public static function maskEmail(?string $email): string
    {
        if (blank($email) || ! str_contains((string) $email, '@')) {
            return '—';
        }

        [$local, $domain] = explode('@', (string) $email, 2);

        return mb_substr($local, 0, 1).str_repeat('•', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
