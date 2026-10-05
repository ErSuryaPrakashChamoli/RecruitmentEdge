<?php

namespace Tests\Feature\Api;

use App\Services\Integrations\Http\HostResolver;

/**
 * SaaS-6 test double: DNS answers by host name, so the SSRF guard is tested without the network.
 * hooks.example.com resolves to a public address unless told otherwise.
 */
final class FakeHostResolver implements HostResolver
{
    public const string PUBLIC_ADDRESS = '93.184.216.34';

    /**
     * @param  array<string, list<string>>  $answers
     */
    public function __construct(public array $answers) {}

    /**
     * @param  array<string, list<string>>  $answers
     */
    public static function install(array $answers = []): self
    {
        $resolver = new self($answers + ['hooks.example.com' => [self::PUBLIC_ADDRESS]]);
        app()->instance(HostResolver::class, $resolver);

        return $resolver;
    }

    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? [];
    }
}
