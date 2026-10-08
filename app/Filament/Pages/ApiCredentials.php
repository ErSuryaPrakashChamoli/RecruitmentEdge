<?php

namespace App\Filament\Pages;

use App\Enums\ApiScope;
use App\Enums\Entitlement;
use App\Filament\Concerns\RevealsSecretOnce;
use App\Models\ApiCredential;
use App\Models\User;
use App\Services\Api\ApiCredentialService;
use App\Services\Entitlements\EntitlementService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * SaaS-6: this organisation's API credentials. Members holding integrations.manage issue
 * credentials that act as themselves (narrowed by scopes) while the organisation is entitled to the
 * API; the token is shown once. Only its owner rotates a credential; any credential administrator
 * revokes one — also without the entitlement, so access can always be cut.
 */
class ApiCredentials extends Page implements HasTable
{
    use InteractsWithTable;
    use RevealsSecretOnce;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'API Credentials';

    protected static ?string $title = 'API Credentials';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('integrations.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ApiCredential::query()->with('owner:id,name'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->description(fn (ApiCredential $record): string => $record->displayKey())->searchable(),
                TextColumn::make('owner.name')->label('Acts as'),
                TextColumn::make('scopes')->badge(),
                TextColumn::make('state')->badge()
                    ->state(fn (ApiCredential $record): string => $record->isRevoked() ? 'Revoked' : ($record->isExpired() ? 'Expired' : 'Active'))
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('expires_at')->label('Expires')->date()->placeholder('—'),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('created_at')->label('Issued')->date(),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label('Issue credential')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => app(EntitlementService::class)->allows(Entitlement::ApiAccess))
                    ->modalDescription('The credential acts as you, in this organisation only, limited to the scopes you choose — and stops working if you lose access.')
                    ->schema(fn (): array => [
                        TextInput::make('name')->required()->maxLength(80),
                        CheckboxList::make('scopes')->required()
                            ->options(collect(app(ApiCredentialService::class)->grantableScopes(self::actor()))->mapWithKeys(fn (ApiScope $scope): array => [$scope->value => $scope->label()])->all()),
                        Select::make('expiry_days')->label('Expires after')->required()->default(90)
                            ->options([30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '365 days']),
                    ])
                    ->action(function (array $data): void {
                        $issued = self::perform(fn (User $actor) => app(ApiCredentialService::class)->issue($actor, (string) $data['name'], array_map(fn (string $scope): ApiScope => ApiScope::from($scope), (array) $data['scopes']), (int) $data['expiry_days']));
                        $this->reveal('Credential issued', 'Token', $issued['token']);
                    }),
            ])
            ->recordActions([
                Action::make('rotate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('The current token stops working at once.')
                    ->visible(fn (ApiCredential $record): bool => $record->isUsable() && (int) $record->user_id === (int) self::actor()->getKey())
                    ->action(function (ApiCredential $record): void {
                        $rotated = self::perform(fn (User $actor) => app(ApiCredentialService::class)->rotate($record, $actor));
                        $this->reveal('Credential rotated', 'New token', $rotated['token']);
                    }),
                Action::make('revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ApiCredential $record): bool => ! $record->isRevoked())
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(function (ApiCredential $record, array $data): void {
                        self::perform(fn (User $actor) => app(ApiCredentialService::class)->revoke($record, $actor, (string) $data['reason']));
                        Notification::make()->title('Credential revoked')->success()->send();
                    }),
            ])
            ->recordUrl(null);
    }

    private static function actor(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $work
     * @return T
     */
    private static function perform(callable $work): mixed
    {
        try {
            return $work(self::actor());
        } catch (DomainException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
