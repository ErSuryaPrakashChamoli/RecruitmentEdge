<?php

namespace App\Logging;

/**
 * Phase 8.7 (D8.7-017/018, SEC-87-07): one place that strips personal data and secrets from text
 * that leaves the request — log records, exception messages and failed-job traces. Applied
 * centrally (the log tap and the failed-job store), so no call site has to remember to redact.
 *
 * Redacted: email addresses, phone numbers (+country-code numbers and 10-digit mobiles), bearer / basic
 * credentials, token / signature / password / key / secret query or JSON values, provider key
 * shapes (sk-…, AIza…), and SQL bindings of statements that write message content. Context arrays
 * are redacted recursively, and values under sensitive key names are replaced whole.
 */
class SensitiveDataRedactor
{
    public const string MASK = '[redacted]';

    /**
     * Context keys whose values are always replaced.
     *
     * @var array<int, string>
     */
    public const array SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'secret', 'api_key', 'apikey', 'authorization',
        'email', 'mobile', 'phone', 'recipient', 'body', 'subject', 'offered_ctc', 'fixed_salary', 'variable_salary', 'joining_bonus',
        'current_salary', 'expected_salary', 'offer_letter_body', 'remarks',
    ];

    /**
     * @var array<string, string>
     */
    private const array PATTERNS = [
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => self::MASK,
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._\-+\/=]+/i' => '$1 '.self::MASK,
        '/\b(token|signature|password|passwd|secret|api[_-]?key|access_token|refresh_token|code)=([^&\s"\']+)/i' => '$1='.self::MASK,
        '/"(token|signature|password|secret|api_key|access_token|refresh_token)"\s*:\s*"[^"]*"/i' => '"$1":"'.self::MASK.'"',
        '/\b(sk-[A-Za-z0-9_\-]{12,}|AIza[0-9A-Za-z_\-]{20,})\b/' => self::MASK,
        '/(?<![\w\/.:-])\+\d[\d \-]{7,14}\d(?![\w\/.:-])/' => self::MASK,
        '/(?<![\w\/.:-])[6-9]\d{9}(?![\w\/.:-])/' => self::MASK,
    ];

    public static function text(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // A failed insert of a candidate message repeats its whole content in the SQL bindings.
        $value = (string) preg_replace('/(insert into [`"]?candidate_communications[`"]?[^()]*\([^)]*\) values\s*)\(.*?\)(?=\)|$|\s)/is', '$1('.self::MASK.')', $value);

        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function context(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $context[$key] = self::MASK;
            } elseif (is_array($value)) {
                $context[$key] = self::context($value);
            } elseif (is_string($value)) {
                $context[$key] = self::text($value);
            }
        }

        return $context;
    }
}
