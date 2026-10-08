<?php

namespace App\Services\Integrations;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\IntegrationStatus;
use App\Services\Integrations\Contracts\ConnectionType;
use App\Services\Integrations\Contracts\InboundWebhookHandler;
use App\Services\Integrations\Contracts\Integration;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-6: also the registry of tenant connection types (webhook endpoints a tenant configures with
 * its own secrets) and of inbound webhook handlers.
 *
 * Every external integration the platform implements, and the honest state of each:
 * implemented (registered here) / configured (credentials present) / operational (the last
 * explicit connection test succeeded). Tests are run on demand by an administrator — never
 * inferred — and the result (never a secret) is stored in integration_statuses and audited.
 */
class IntegrationRegistry
{
    /**
     * @var array<string, class-string<Integration>>
     */
    private array $integrations = [];

    /**
     * SaaS-6: kinds of tenant-owned connections (IntegrationConnection.type).
     *
     * @var array<string, class-string<ConnectionType>>
     */
    private array $connectionTypes = [];

    /**
     * SaaS-6: what an inbound webhook connection may do with its events.
     *
     * @var array<string, class-string<InboundWebhookHandler>>
     */
    private array $inboundHandlers = [];

    /**
     * @param  class-string<ConnectionType>  $class
     */
    public function registerConnectionType(string $key, string $class): void
    {
        $this->connectionTypes[$key] = $class;
    }

    public function connectionType(string $key): ?ConnectionType
    {
        return isset($this->connectionTypes[$key]) ? app($this->connectionTypes[$key]) : null;
    }

    /**
     * @return array<string, ConnectionType>
     */
    public function connectionTypes(): array
    {
        return array_map(fn (string $class): ConnectionType => app($class), $this->connectionTypes);
    }

    /**
     * @param  class-string<InboundWebhookHandler>  $class
     */
    public function registerInboundHandler(string $key, string $class): void
    {
        $this->inboundHandlers[$key] = $class;
    }

    public function inboundHandler(string $key): ?InboundWebhookHandler
    {
        return isset($this->inboundHandlers[$key]) ? app($this->inboundHandlers[$key]) : null;
    }

    /**
     * @return array<string, InboundWebhookHandler>
     */
    public function inboundHandlers(): array
    {
        return array_map(fn (string $class): InboundWebhookHandler => app($class), $this->inboundHandlers);
    }

    /**
     * @param  class-string<Integration>  $class
     */
    public function register(string $key, string $class): void
    {
        $this->integrations[$key] = $class;
    }

    public function get(string $key): ?Integration
    {
        return isset($this->integrations[$key]) ? app($this->integrations[$key]) : null;
    }

    /**
     * @return Collection<int, array{key: string, label: string, category: string, configured: bool, operational: bool|null, last_test_message: string|null, last_tested_at: Carbon|null}>
     */
    public function overview(): Collection
    {
        $statuses = IntegrationStatus::query()->get()->keyBy('provider');

        return collect($this->integrations)->keys()->map(function (string $key) use ($statuses): array {
            $integration = $this->get($key);
            $status = $statuses->get($key);

            return [
                'key' => $key,
                'label' => $integration->label(),
                'category' => $integration->category(),
                'configured' => $integration->isConfigured(),
                'operational' => $status?->last_test_ok,
                'last_test_message' => $status?->last_test_message,
                'last_tested_at' => $status?->last_tested_at,
            ];
        })->values();
    }

    public function isOperational(string $key): bool
    {
        return (bool) IntegrationStatus::query()->where('provider', $key)->value('last_test_ok');
    }

    public function test(string $key, ?Employee $actor = null): IntegrationStatus
    {
        $integration = $this->get($key) ?? throw new DomainException("Unknown integration \"{$key}\".");

        try {
            $result = $integration->testConnection();
            $ok = $result->ok;
            $message = $result->message;
        } catch (Throwable $e) {
            $ok = false;
            $message = 'Connection test crashed: '.class_basename($e);
            Log::warning('Integration connection test failed', ['integration' => $key, 'exception' => class_basename($e)]);
        }

        $status = IntegrationStatus::query()->updateOrCreate(['provider' => $key], [
            'category' => $integration->category(),
            'last_test_ok' => $ok,
            'last_test_message' => mb_substr($message, 0, 1000),
            'last_tested_at' => now(),
            'last_tested_by' => $actor?->id,
        ]);

        AuditLog::record($status, 'integration_tested', null, ['provider' => $key, 'ok' => $ok, 'message' => $status->last_test_message]);

        return $status;
    }
}
