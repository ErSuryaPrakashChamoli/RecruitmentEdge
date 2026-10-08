<?php

namespace App\Filament\Pages;

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\WebhookEventType;
use App\Filament\Concerns\RevealsSecretOnce;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Integrations\Connections\InboundWebhookConnection;
use App\Services\Integrations\Connections\OutboundWebhookConnection;
use App\Services\Integrations\Contracts\InboundWebhookHandler;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Integrations\IntegrationRegistry;
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
 * SaaS-6: this organisation's webhook connections — endpoints that receive signed event
 * notifications, and sources that send signed events to their own URL. Members holding
 * integrations.manage manage them while the organisation is entitled to webhooks; a secret is shown
 * once. Disabling is always possible.
 */
class IntegrationConnections extends Page implements HasTable
{
    use InteractsWithTable;
    use RevealsSecretOnce;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Webhooks';

    protected static ?string $title = 'Webhooks';

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
        $entitled = fn (): bool => app(EntitlementService::class)->allows(Entitlement::IntegrationsWebhooks);

        return $table
            ->query(fn () => IntegrationConnection::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()
                    ->description(fn (IntegrationConnection $record): string => $record->type === OutboundWebhookConnection::KEY ? (string) strtok((string) ($record->config['url'] ?? ''), '?#') : (string) route('api.v1.hooks.receive', $record->public_key)),
                TextColumn::make('type')->formatStateUsing(fn (string $state): string => app(IntegrationRegistry::class)->connectionType($state)?->label() ?? $state),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ConnectionStatus $state): string => $state->label())
                    ->color(fn (ConnectionStatus $state): string => $state->color()),
                TextColumn::make('config.events')->label('Events / handler')->badge()
                    ->state(fn (IntegrationConnection $record): array => $record->type === OutboundWebhookConnection::KEY ? (array) ($record->config['events'] ?? []) : [(string) ($record->config['handler'] ?? '')]),
                TextColumn::make('last_success_at')->label('Last success')->since()->placeholder('Never'),
                TextColumn::make('last_failure_at')->label('Last failure')->since()->placeholder('—')->tooltip(fn (IntegrationConnection $record): ?string => $record->last_error),
                TextColumn::make('consecutive_failures')->label('Failures in a row')->numeric(),
            ])
            ->headerActions([
                Action::make('createOutbound')
                    ->label('Add endpoint')
                    ->icon('heroicon-o-arrow-up-right')
                    ->visible($entitled)
                    ->modalDescription('Your endpoint receives signed POST requests (RE-Signature) for the events you choose — identifiers only; read the details through the API.')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(80),
                        TextInput::make('url')->label('HTTPS URL')->required()->url()->maxLength(2000),
                        CheckboxList::make('events')->required()->options(self::eventOptions()),
                    ])
                    ->action(function (array $data): void {
                        $created = self::perform(fn (User $actor) => app(IntegrationConnectionService::class)->createOutbound($actor, (string) $data['name'], (string) $data['url'], self::events($data['events'] ?? [])));
                        $this->reveal('Endpoint added', 'Signing secret', $created['secret']);
                    }),
                Action::make('createInbound')
                    ->label('Add inbound source')
                    ->icon('heroicon-o-arrow-down-left')
                    ->visible($entitled)
                    ->modalDescription('Your system posts signed events to a URL of its own; each event is processed once.')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(80),
                        Select::make('handler')->label('What it does')->required()
                            ->options(collect(app(IntegrationRegistry::class)->inboundHandlers())->map(fn (InboundWebhookHandler $handler): string => $handler->label())->all()),
                    ])
                    ->action(function (array $data): void {
                        $created = self::perform(fn (User $actor) => app(IntegrationConnectionService::class)->createInbound($actor, (string) $data['name'], (string) $data['handler']));
                        $this->reveal('Inbound source added', 'Signing secret', $created['secret'], route('api.v1.hooks.receive', $created['connection']->public_key));
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (IntegrationConnection $record): bool => $record->type === OutboundWebhookConnection::KEY && $entitled())
                    ->fillForm(fn (IntegrationConnection $record): array => ['url' => $record->config['url'] ?? null, 'events' => $record->config['events'] ?? []])
                    ->schema([
                        TextInput::make('url')->label('HTTPS URL')->required()->url()->maxLength(2000),
                        CheckboxList::make('events')->required()->options(self::eventOptions()),
                    ])
                    ->action(function (IntegrationConnection $record, array $data): void {
                        self::perform(fn (User $actor) => app(IntegrationConnectionService::class)->updateOutbound($record, $actor, (string) $data['url'], self::events($data['events'] ?? [])));
                        Notification::make()->title('Endpoint updated')->success()->send();
                    }),
                Action::make('rotate')
                    ->label('Rotate secret')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('The current secret keeps working for '.config('api.webhooks.rotation_overlap_hours', 24).' hours, so you can switch over.')
                    ->visible(fn (IntegrationConnection $record): bool => $record->isActive() && $entitled())
                    ->action(function (IntegrationConnection $record): void {
                        $rotated = self::perform(fn (User $actor) => app(IntegrationConnectionService::class)->rotateSecret($record, $actor));
                        $this->reveal('Secret rotated', 'New signing secret', $rotated['secret'], $record->type === InboundWebhookConnection::KEY ? route('api.v1.hooks.receive', $record->public_key) : null);
                    }),
                Action::make('disable')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (IntegrationConnection $record): bool => $record->isActive())
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(function (IntegrationConnection $record, array $data): void {
                        self::perform(fn (User $actor) => app(IntegrationConnectionService::class)->disable($record, $actor, (string) $data['reason']));
                        Notification::make()->title('Connection disabled')->success()->send();
                    }),
                Action::make('enable')
                    ->color('success')
                    ->visible(fn (IntegrationConnection $record): bool => ! $record->isActive() && $entitled())
                    ->action(function (IntegrationConnection $record): void {
                        self::perform(fn (User $actor) => app(IntegrationConnectionService::class)->enable($record, $actor));
                        Notification::make()->title('Connection enabled')->success()->send();
                    }),
            ])
            ->recordUrl(null);
    }

    /**
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        return collect(WebhookEventType::cases())->mapWithKeys(fn (WebhookEventType $type): array => [$type->value => $type->label().' ('.$type->value.')'])->all();
    }

    /**
     * @param  array<int, string>  $values
     * @return list<WebhookEventType>
     */
    private static function events(array $values): array
    {
        return array_values(array_filter(array_map(fn (string $value): ?WebhookEventType => WebhookEventType::tryFrom($value), $values)));
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
