<?php

namespace App\Services\Integrations\Calendar;

use App\Models\AuditLog;
use App\Models\CalendarConnection;
use App\Models\Employee;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connecting an employee's external calendar via OAuth (Phase 5), keeping its tokens fresh, and
 * disconnecting it. Tokens go straight into the encrypted CalendarConnection columns and are never
 * logged; connect/disconnect/refresh failures are audited without secrets.
 */
class CalendarConnectionService
{
    public function __construct(private readonly CalendarManager $calendars) {}

    /**
     * @return array{url: string, state: string}
     */
    public function authorizationRequest(string $provider, string $redirectUri): array
    {
        $adapter = $this->configured($provider);
        $state = Str::random(40);

        return ['url' => $adapter->authorizationUrl($state, $redirectUri), 'state' => $state];
    }

    public function complete(Employee $employee, string $provider, string $code, string $redirectUri): CalendarConnection
    {
        $tokens = $this->configured($provider)->exchangeCode($code, $redirectUri);

        $connection = CalendarConnection::query()->firstOrNew(['employee_id' => $employee->id, 'provider' => $provider]);
        $connection->fill([
            'account_email' => $tokens->accountEmail,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
            'token_expires_at' => $tokens->expiresAt,
            'status' => 'active',
            'last_error' => null,
            'connected_at' => now(),
        ])->save();

        AuditLog::record($connection, 'calendar_connected', null, ['provider' => $provider, 'account_email' => $tokens->accountEmail]);

        return $connection;
    }

    public function disconnect(CalendarConnection $connection): void
    {
        $connection->forceFill(['access_token' => null, 'refresh_token' => null, 'status' => 'disconnected', 'token_expires_at' => null])->save();

        AuditLog::record($connection, 'calendar_disconnected', null, ['provider' => $connection->provider]);
    }

    /**
     * Refreshes an expired access token in place. On failure the connection is marked `error`
     * (the employee must reconnect) and the reason is recorded — never the token.
     */
    public function ensureFreshToken(CalendarConnection $connection): CalendarConnection
    {
        if (! $connection->tokenExpired()) {
            return $connection;
        }

        try {
            $tokens = $this->configured($connection->provider)->refresh($connection);
        } catch (Throwable $e) {
            $connection->forceFill(['status' => 'error', 'last_error' => 'Token refresh failed — reconnect this calendar.'])->save();
            AuditLog::record($connection, 'calendar_token_refresh_failed', null, ['provider' => $connection->provider, 'error' => class_basename($e)]);
            Log::warning('Calendar token refresh failed', ['calendar_connection_id' => $connection->id, 'provider' => $connection->provider]);

            throw new DomainException('The calendar connection has expired; the employee must reconnect it.');
        }

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt,
        ])->save();

        return $connection;
    }

    public function activeConnectionFor(Employee $employee): ?CalendarConnection
    {
        return CalendarConnection::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->latest('connected_at')
            ->first();
    }

    private function configured(string $provider): CalendarProvider
    {
        $adapter = $this->calendars->find($provider);

        if ($adapter === null || ! $adapter->isConfigured()) {
            throw new DomainException('This calendar provider is not configured. Ask an administrator to add its OAuth credentials.');
        }

        return $adapter;
    }
}
