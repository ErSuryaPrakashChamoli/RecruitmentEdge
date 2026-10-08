<?php

namespace App\Filament\Pages\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * SaaS-2 (prompt §17): the staff "Forgot password?" page never reveals whether an address belongs
 * to anyone. Filament answers "We can't find a user with that email address." for an unknown
 * address and "Please wait before retrying." for a known one asked again too soon; here every
 * outcome — sent, unknown, throttled, an identity that can enter no tenant — shows the same "sent"
 * message. The link itself is the global credential's (one password for every tenant the person
 * belongs to); the mail is queued (encrypted), so a known address does not answer more slowly.
 */
class StaffRequestPasswordReset extends RequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                if ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
                    return;
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                $user->notify($notification);

                event(new PasswordResetLinkSent($user));
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            Log::info('identity.password_reset_not_sent', ['status' => $status]);
        }

        $this->getSentNotification(Password::RESET_LINK_SENT)?->send();

        $this->form->fill();
    }
}
