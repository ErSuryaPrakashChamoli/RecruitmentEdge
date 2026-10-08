<?php

namespace App\Services\Integrations\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SaaS-6: the only way to a tenant-chosen URL (outbound webhooks). Checked when the URL is saved
 * and again before every call:
 *
 * - https only; no user name or password in the URL; a port from api.webhooks.allowed_ports;
 * - no whitespace, control characters or backslashes, and the host an IP literal or a plain DNS
 *   name (no numeric or hex shorthand), so every URL parser reads the same host;
 * - the host — a name or a literal address — must resolve, and EVERY address must be public: no
 *   loopback, private, link-local (cloud metadata), carrier-grade NAT, multicast, reserved,
 *   documentation, IPv6 unique-local/link-local, or IPv4-mapped/NAT64 forms of any of them;
 * - the caller connects to the checked address only (SafeDestination::pin), with redirects off, so
 *   neither a redirect nor a changed DNS answer (rebinding) can reach an internal address.
 */
class OutboundUrlGuard
{
    /**
     * Address ranges never reached from the platform.
     */
    public const array BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/23', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    private const string HOST_NAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    public function __construct(private readonly HostResolver $resolver) {}

    public function check(string $url): SafeDestination
    {
        // Whitespace, control characters and backslashes are where URL parsers disagree.
        if (preg_match('/[\s\x00-\x1f\x7f\\\\]/', trim($url)) === 1) {
            throw new UnsafeDestination('The URL contains characters that are not allowed.');
        }

        $parts = parse_url(trim($url));

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || blank($parts['host'] ?? null)) {
            throw new UnsafeDestination('Use an https:// URL with a host name.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeDestination('The URL must not contain a user name or password.');
        }

        $port = (int) ($parts['port'] ?? 443);

        if (! in_array($port, array_map('intval', (array) config('api.webhooks.allowed_ports', [443])), true)) {
            throw new UnsafeDestination('That port is not allowed for webhooks.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // A host that every parser reads the same way: an IP literal or a plain DNS name with an
        // alphabetic top-level domain — no numeric or hex shorthand (2130706433, 0x7f000001, 127.1).
        if (! $literal && preg_match(self::HOST_NAME, $host) !== 1) {
            throw new UnsafeDestination('Use a fully qualified host name or an IP address.');
        }

        $addresses = $literal ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new UnsafeDestination('The host name does not resolve.');
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new UnsafeDestination('The URL points to a private or reserved network address.');
            }
        }

        return new SafeDestination(trim($url), $host, $port, $addresses[0]);
    }

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // An IPv4-mapped or NAT64 IPv6 address is judged by the IPv4 address it carries.
        if (preg_match('/^(?:::ffff:|64:ff9b::)(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $embedded) === 1) {
            return self::isPublic($embedded[1]);
        }

        return ! IpUtils::checkIp($address, self::BLOCKED_RANGES);
    }
}
