<?php

namespace App\Filament\Platform\Concerns;

use App\Enums\PlatformCapability;
use App\Models\User;
use App\Services\Platform\PlatformAuthorization;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * SaaS-5: the platform panel's pages mirror PlatformAuthorization (the services decide again), and
 * show a service's refusal as a message rather than an error page.
 */
trait InteractsWithPlatform
{
    protected static function allows(PlatformCapability $capability): bool
    {
        return app(PlatformAuthorization::class)->can(self::signedIn(), $capability);
    }

    protected static function signedIn(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function operator(): User
    {
        $user = self::signedIn();
        abort_unless($user !== null, 403);

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $work
     * @return T
     */
    protected static function perform(callable $work, string $done): mixed
    {
        try {
            $result = $work(self::operator());
        } catch (DomainException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($done)->success()->send();

        return $result;
    }
}
