<?php

namespace App\Services\Identity;

use App\Models\AuditLog;
use App\Models\User;
use DomainException;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Uri;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 8.4: credential changes an administrator makes for someone else, and sign-out-everywhere.
 *
 * - An administrator-set password obeys the same policy as everyone's (Password::defaults()),
 *   signs the person out everywhere and is audited (never the value).
 * - An administrator never changes a login's email directly: the new address gets a verification
 *   link (Filament's email-change flow) and the change applies only once the person confirms it
 *   while signed in; the old address is told and can block it.
 * - Sign out everywhere: yourself (keeping this session), or someone in your hierarchy
 *   (users.access.manage).
 */
class CredentialService
{
    /**
     * Set while this service writes a password, so the User model does not record a second,
     * self-service "password_changed" row for the same change.
     */
    public static bool $writingPassword = false;

    public function __construct(
        private readonly SessionRevocationService $sessions,
        private readonly AuthorityGuard $authority,
    ) {}

    public function setPasswordByAdministrator(User $target, string $password, User $actor): void
    {
        if (! $actor->can('users.manage')) {
            throw new DomainException('Setting a password needs the users.manage permission.');
        }

        $this->authority->assertNotSelf($actor, $target, 'Change your own password on your profile page.');
        $this->authority->assertInScope($actor, $target);
        self::assertAcceptablePassword($password);

        self::$writingPassword = true;

        try {
            $target->forceFill(['password' => $password])->save();
        } finally {
            self::$writingPassword = false;
        }

        $this->sessions->revokeAll($target, reason: 'password_reset_by_admin');

        AuditLog::record($target, 'password_reset_by_admin', null, ['by_user_id' => $actor->id]);
        Log::info('identity.password_reset_by_admin', ['user_id' => $target->id, 'actor_id' => $actor->id]);
    }

    /**
     * Sends the verification link for a new email address. Nothing changes until the person opens
     * it while signed in as themselves.
     */
    public function requestEmailChange(User $target, string $newEmail, User $actor): void
    {
        if (! $actor->can('users.manage')) {
            throw new DomainException('Changing an email needs the users.manage permission.');
        }

        $this->authority->assertNotSelf($actor, $target, 'Change your own email on your profile page.');
        $this->authority->assertInScope($actor, $target);

        if (strcasecmp($target->email, $newEmail) === 0) {
            return;
        }

        if (User::query()->whereKeyNot($target->id)->where('email', $newEmail)->exists()) {
            throw new DomainException('Another login already uses this email address.');
        }

        $notification = app(VerifyEmailChange::class);
        $notification->url = Filament::getVerifyEmailChangeUrl($target, $newEmail);
        $signature = (string) Uri::of($notification->url)->query()->get('signature');

        cache()->put($signature, true, ttl: now()->addHour());

        $target->notify(app(NoticeOfEmailChangeRequest::class, [
            'blockVerificationUrl' => Filament::getBlockEmailChangeVerificationUrl($target, $newEmail, $signature),
            'newEmail' => $newEmail,
        ]));
        Notification::route('mail', $newEmail)->notify($notification);

        self::recordEmailChangeRequest($target, $newEmail, $actor);
    }

    public static function recordEmailChangeRequest(User $target, string $newEmail, ?User $actor): void
    {
        AuditLog::record($target, 'email_change_requested', ['email' => $target->email], ['email' => $newEmail, 'by_user_id' => $actor?->id, 'pending_verification' => true]);
        Log::info('identity.email_change_requested', ['user_id' => $target->id, 'actor_id' => $actor?->id]);
    }

    /**
     * Ends every session of $target. For yourself the current session is kept.
     */
    public function signOutEverywhere(User $target, User $actor, ?Session $current = null): int
    {
        if ($actor->isNot($target)) {
            $this->authority->assertCanManageAccess($actor, $target);
        }

        $deleted = $this->sessions->revokeAll($target, $actor->is($target) ? $current : null, $actor->is($target) ? 'sign_out_other_sessions' : 'sign_out_everywhere_by_admin');

        AuditLog::record($target, 'signed_out_everywhere', null, ['by_user_id' => $actor->id, 'kept_current_session' => $actor->is($target) && $current !== null]);

        return $deleted;
    }

    /**
     * The staff password policy (Password::defaults()), enforced in the service as well as the form.
     */
    public static function assertAcceptablePassword(string $password): void
    {
        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', Password::defaults()]]);

        if ($validator->fails()) {
            throw new DomainException(implode(' ', $validator->errors()->all()));
        }
    }
}
