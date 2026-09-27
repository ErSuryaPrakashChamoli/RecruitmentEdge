<?php

namespace App\Filament\Resources\Users\Actions;

use App\Enums\AccessState;
use App\Models\User;
use App\Services\Identity\AuthorityGuard;
use App\Services\Identity\CredentialService;
use App\Services\Identity\MfaService;
use App\Services\Identity\StaffAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Phase 8.4: the confirmed, audited access actions for a login (Users screens and the Access
 * Review). Each is offered only to someone who may take it (users.access.manage, in scope, never
 * on themselves); the services enforce the same rules — the UI is not the security boundary.
 */
class UserAccessActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::suspend(), self::restore(), self::revoke(), self::signOutEverywhere(), self::resetMfa()];
    }

    public static function suspend(): Action
    {
        return self::stateAction('suspendAccess', 'Suspend access', 'heroicon-o-pause-circle', 'warning',
            'The login is blocked and every session ends. Roles stay, dormant, until access is restored.',
            fn (User $record) => $record->access_status === AccessState::Active,
            fn (StaffAccessService $access, User $record, User $actor, string $reason) => $access->suspend($record, $actor, $reason),
            'Access suspended');
    }

    public static function restore(): Action
    {
        return self::stateAction('restoreAccess', 'Restore access', 'heroicon-o-play-circle', 'success',
            'A suspended login gets its roles back. A revoked login comes back with the base role only — grant anything more explicitly.',
            fn (User $record) => $record->access_status !== AccessState::Active,
            fn (StaffAccessService $access, User $record, User $actor, string $reason) => $access->restore($record, $actor, $reason),
            'Access restored');
    }

    public static function revoke(): Action
    {
        return self::stateAction('revokeAccess', 'Revoke access', 'heroicon-o-no-symbol', 'danger',
            'Ends this person\'s authority: every role is removed, every session ends and their pending AI actions are cancelled. Their history is kept.',
            fn (User $record) => $record->access_status !== AccessState::Revoked,
            fn (StaffAccessService $access, User $record, User $actor, string $reason) => $access->revoke($record, $actor, $reason),
            'Access revoked');
    }

    public static function signOutEverywhere(): Action
    {
        return Action::make('signOutEverywhere')
            ->label('Sign out everywhere')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Every browser and device signed in as this person is signed out.')
            ->visible(fn (User $record): bool => self::canManage($record))
            ->action(fn (User $record) => self::perform(fn (User $actor) => app(CredentialService::class)->signOutEverywhere($record, $actor), 'Signed out everywhere'));
    }

    public static function resetMfa(): Action
    {
        return Action::make('resetMfa')
            ->label('Reset MFA')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Removes this person\'s authenticator app and recovery codes (a lost device). They are signed out and set MFA up again at their next sign-in.')
            ->visible(fn (User $record): bool => filled($record->getAppAuthenticationSecret()) && self::canManage($record))
            ->action(fn (User $record) => self::perform(fn (User $actor) => app(MfaService::class)->resetFor($record, $actor), 'MFA reset'));
    }

    /**
     * @param  callable(User): bool  $applies
     * @param  callable(StaffAccessService, User, User, string): mixed  $operation
     */
    private static function stateAction(string $name, string $label, string $icon, string $color, string $description, callable $applies, callable $operation, string $success): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->visible(fn (User $record): bool => $applies($record) && self::canManage($record))
            ->action(fn (User $record, array $data) => self::perform(fn (User $actor) => $operation(app(StaffAccessService::class), $record, $actor, $data['reason']), $success));
    }

    private static function canManage(User $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isNot($record) && $actor->can('users.access.manage') && app(AuthorityGuard::class)->inScope($actor, $record);
    }

    /**
     * @param  callable(User): mixed  $operation
     */
    private static function perform(callable $operation, string $success): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation($actor);
        } catch (DomainException $e) {
            Notification::make()->title('Access could not be changed')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($success)->success()->send();
    }
}
