<?php

namespace App\Services\Api;

use RuntimeException;

/**
 * SaaS-6: an API refusal with its stable error code and HTTP status — rendered by ApiErrorRenderer
 * as {"error": {"code", "message", "request_id"}}. Messages never name another tenant, a secret or
 * an internal identifier.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $headers = [])
    {
        parent::__construct($message);
    }

    public static function unauthenticated(string $message = 'A valid API credential is required.'): self
    {
        return new self('unauthenticated', $message, 401, ['WWW-Authenticate' => 'Bearer']);
    }

    public static function invalidCredential(): self
    {
        return new self('invalid_credential', 'The API credential is not valid.', 401, ['WWW-Authenticate' => 'Bearer']);
    }

    public static function inactiveCredential(): self
    {
        return new self('credential_inactive', 'The API credential has been revoked, has expired, or its owner no longer has API access.', 401, ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self($code, $message, 403);
    }
}
