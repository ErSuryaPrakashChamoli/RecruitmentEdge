<?php

namespace App\Logging;

use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Stringable;
use Throwable;

/**
 * Phase 8.7 (SEC-87-07, D8.7-012): wraps the configured failed-job store so the exception text kept
 * in `failed_jobs` passes through SensitiveDataRedactor (provider errors can quote an address or a
 * number; a failed insert quotes its bindings). The payload itself is already ids-only or
 * encrypted (D8.7-017). Everything else — listing, retry, forget, prune — is delegated unchanged.
 */
class RedactingFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(private readonly FailedJobProviderInterface $inner) {}

    public function log($connection, $queue, $payload, $exception)
    {
        $redacted = new class($exception) implements Stringable
        {
            public function __construct(private readonly Throwable|string $exception) {}

            public function __toString(): string
            {
                return (string) SensitiveDataRedactor::text((string) $this->exception);
            }
        };

        return $this->inner->log($connection, $queue, $payload, $redacted);
    }

    public function ids($queue = null)
    {
        return $this->inner->ids($queue);
    }

    public function all()
    {
        return $this->inner->all();
    }

    public function find($id)
    {
        return $this->inner->find($id);
    }

    public function forget($id)
    {
        return $this->inner->forget($id);
    }

    public function flush($hours = null)
    {
        $this->inner->flush($hours);
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->inner instanceof PrunableFailedJobProvider ? $this->inner->prune($before) : 0;
    }

    public function count($connection = null, $queue = null)
    {
        return $this->inner instanceof CountableFailedJobProvider ? $this->inner->count($connection, $queue) : count($this->inner->all());
    }
}
