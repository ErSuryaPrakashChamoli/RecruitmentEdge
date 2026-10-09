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
    /**
     * This request's answers, so a page asks once per capability rather than once per row or
     * action. Never carried to the next request: Livewire keeps only public properties.
     *
     * @var array<string, bool>
     */
    private array $platformPermits = [];

    protected static function allows(PlatformCapability $capability): bool
    {
        return app(PlatformAuthorization::class)->can(self::signedIn(), $capability);
    }

    /**
     * allows(), asked once per request on this page or widget.
     */
    protected function permits(PlatformCapability $capability): bool
    {
        return $this->platformPermits[$capability->value] ??= self::allows($capability);
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
     * @param  string|callable(T): Notification  $done  the success title, or the notification to send for the result
     * @return T
     */
    protected static function perform(callable $work, string|callable $done): mixed
    {
        try {
            $result = $work(self::operator());
        } catch (DomainException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        (is_string($done) ? Notification::make()->title($done)->success() : $done($result))->send();

        return $result;
    }
}
