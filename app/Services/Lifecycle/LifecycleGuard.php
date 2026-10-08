<?php

namespace App\Services\Lifecycle;

use Closure;

/**
 * Phase 8.3 — One Path per Hiring Fact. Lifecycle attributes (an application's stage/status and
 * relationships, an interview's status/time/interviewer, an offer's status and released terms, a
 * joining's status, a requisition's status) may only change inside a scope opened by their
 * authoritative service. Everything else — a Filament form save, a table action, a tool, an
 * automation handler — is refused by the model (GuardsLifecycleAttributes), so a bypass fails
 * loudly instead of silently rewriting a hiring fact.
 */
final class LifecycleGuard
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function allow(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }
}
