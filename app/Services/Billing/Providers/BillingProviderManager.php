<?php

namespace App\Services\Billing\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-4: resolves provider adapters by name. One adapter instance per provider per request. The
 * fake adapter exists for development and tests only and is refused anywhere else, so a
 * misconfigured production can never accept fake-signed payments.
 */
class BillingProviderManager
{
    /**
     * @var array<string, class-string<BillingProvider>>
     */
    public const ADAPTERS = [
        'fake' => FakeBillingProvider::class,
    ];

    /**
     * @var array<string, BillingProvider>
     */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function default(): BillingProvider
    {
        return $this->find((string) config('billing.provider')) ?? throw new ProviderUnavailable('No billing provider is configured.');
    }

    public function find(string $name): ?BillingProvider
    {
        $class = self::ADAPTERS[$name] ?? null;

        if ($class === null) {
            return null;
        }

        if ($class === FakeBillingProvider::class && ! app()->environment(['local', 'testing'])) {
            Log::warning('billing.fake_provider_refused', ['environment' => app()->environment()]);

            return null;
        }

        return $this->resolved[$name] ??= $this->container->make($class);
    }
}
