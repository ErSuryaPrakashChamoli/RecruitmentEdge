<?php

namespace App\Services\Webhooks;

/**
 * The application's one webhook signature scheme (SaaS-4 billing, SaaS-6 webhooks):
 * `t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, with one v1 per valid secret (a
 * secret being rotated signs alongside the new one). Verification accepts any v1 made with any of
 * the given secrets, within the timestamp tolerance, compared in constant time.
 */
final class WebhookSignature
{
    /**
     * @param  list<string>  $secrets  every secret to sign with (current first)
     */
    public static function header(string $body, int $timestamp, array $secrets): string
    {
        $parts = ['t='.$timestamp];

        foreach ($secrets as $secret) {
            $parts[] = 'v1='.self::compute($body, $timestamp, $secret);
        }

        return implode(',', $parts);
    }

    /**
     * @param  list<string>  $secrets  every secret currently accepted
     */
    public static function verify(string $header, string $body, array $secrets, int $toleranceSeconds, ?int $now = null): bool
    {
        if (preg_match('/^t=(\d{1,12})((?:,v1=[a-f0-9]{64}){1,4})$/', $header, $parts) !== 1) {
            return false;
        }

        $timestamp = (int) $parts[1];

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        preg_match_all('/v1=([a-f0-9]{64})/', $parts[2], $signatures);
        $valid = false;

        foreach (array_filter($secrets, fn (string $secret): bool => $secret !== '') as $secret) {
            $expected = self::compute($body, $timestamp, $secret);

            foreach ($signatures[1] as $signature) {
                // No early exit: every comparison is made, in constant time.
                $valid = hash_equals($expected, $signature) || $valid;
            }
        }

        return $valid;
    }

    private static function compute(string $body, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
