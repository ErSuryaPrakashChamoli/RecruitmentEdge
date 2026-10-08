<?php

namespace App\Filament\Resources\Users\Actions;

use App\Enums\Entitlement;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Models\Role;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Identity\TenantInvitationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * SaaS-2: the Users screen invites people instead of creating logins. The administrator chooses the
 * roles (only ones they may grant) and, optionally, the employee record; TenantInvitationService
 * enforces every rule. The confirmation is the same whether or not the address already has an
 * identity on the platform (S1-01).
 */
class InviteMemberAction
{
    public static function make(): Action
    {
        return Action::make('inviteMember')
            ->label('Invite member')
            ->icon('heroicon-o-envelope')
            ->visible(fn (): bool => auth()->user()?->can('create', User::class) ?? false)
            // SaaS-3: no free staff seat, no invitation (acceptance takes the seat; both refuse anyway).
            ->disabled(fn (): bool => ! app(EntitlementService::class)->canAdd(Entitlement::MembersActiveMax))
            ->tooltip(fn (): ?string => app(EntitlementService::class)->canAdd(Entitlement::MembersActiveMax) ? null : Entitlement::MembersActiveMax->unavailableMessage())
            ->modalDescription('They get an email link to join this organisation — signing in with their existing account, or creating one.')
            ->schema([
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->label('Name (optional)')
                    ->maxLength(255),
                Select::make('employee_id')
                    ->label('Link to employee record')
                    ->options(fn (): array => UserForm::employeeOptions(null))
                    ->searchable()
                    ->helperText('Only current employees in your hierarchy without a login are listed.'),
                CheckboxList::make('roles')
                    ->options(fn (): array => Role::query()->forCurrentTenant()->orderBy('name')->pluck('name', 'id')->all())
                    ->disableOptionWhen(fn (string $value): bool => ! UserForm::canGrant((int) $value))
                    ->helperText('You can only grant roles whose permissions you hold; protected roles only by their holders.')
                    ->columns(2)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    app(TenantInvitationService::class)->invite($data, $actor);
                } catch (DomainException $e) {
                    Notification::make()->title('Invitation not sent')->body($e->getMessage())->danger()->persistent()->send();

                    throw new Halt;
                }

                Notification::make()->title('Invitation sent')->body('An invitation to join was sent to '.User::normaliseEmail((string) $data['email']).'.')->success()->send();
            });
    }
}
