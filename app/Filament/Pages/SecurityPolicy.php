<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Identity\TenantSecurityPolicyService;
use App\Services\Tenancy\TenantContext;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * SaaS-2: this organisation's security policy — whether every member must use multi-factor
 * authentication. Changed through TenantSecurityPolicyService (users.access.manage, a reason,
 * audited). A person who belongs to any organisation requiring MFA uses it everywhere they sign in.
 */
class SecurityPolicy extends Page
{
    protected string $view = 'filament.pages.security-policy';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Security Policy';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('users.access.manage');
    }

    public function mfaRequired(): bool
    {
        return (bool) TenantContext::current()->requireTenant()->mfa_required;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('requireMfa')
                ->label('Require MFA for every member')
                ->icon('heroicon-o-lock-closed')
                ->requiresConfirmation()
                ->modalDescription('Every member must set up an authenticator app at their next request, and sign in with it in every organisation they belong to.')
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
                ->visible(fn (): bool => ! $this->mfaRequired())
                ->action(fn (array $data) => $this->change(true, $data['reason'])),
            Action::make('stopRequiringMfa')
                ->label('Stop requiring MFA for every member')
                ->icon('heroicon-o-lock-open')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('MFA stays required for privileged roles and for anyone another organisation requires it of.')
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
                ->visible(fn (): bool => $this->mfaRequired())
                ->action(fn (array $data) => $this->change(false, $data['reason'])),
        ];
    }

    private function change(bool $required, string $reason): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            app(TenantSecurityPolicyService::class)->setMfaRequired($required, $actor, $reason);
        } catch (DomainException $e) {
            Notification::make()->title('Policy not changed')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($required ? 'MFA is now required for every member' : 'MFA is no longer required for every member')->success()->send();
    }
}
