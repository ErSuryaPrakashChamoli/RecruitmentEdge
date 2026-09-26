<?php

namespace App\Filament\Concerns;

use DomainException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Runs a domain-service call from a Filament action, turning a DomainException into a danger
 * notification and a Halt (see .ai/rules/resources-filament-pages.md) instead of a 500 page over
 * the panel.
 */
trait GuardsDomainExceptions
{
    /**
     * @param  callable(): mixed  $callback
     */
    public static function guarded(string $failureTitle, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $e) {
            Notification::make()
                ->title($failureTitle)
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}
