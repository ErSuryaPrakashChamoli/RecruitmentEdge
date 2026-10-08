<?php

namespace App\Filament\Pages;

use App\Enums\InboundWebhookStatus;
use App\Models\InboundWebhookEvent;
use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * SaaS-6: inbound webhook events received — what happened to each (never its payload), and
 * processing again (audited) for one that failed or was ignored.
 */
class InboundWebhookEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Inbound Webhook Events';

    protected static ?string $title = 'Inbound Webhook Events';

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
            ->query(fn () => InboundWebhookEvent::query()->select(['id', 'tenant_id', 'integration_connection_id', 'external_id', 'type', 'status', 'attempts', 'received_at', 'processed_at', 'last_error', 'result', 'reprocess_count'])->with('connection:id,name'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('external_id')->label('Event id')->description(fn (InboundWebhookEvent $record): string => $record->type)->searchable(),
                TextColumn::make('connection.name')->label('Source'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (InboundWebhookStatus $state): string => $state->label())
                    ->color(fn (InboundWebhookStatus $state): string => $state->color()),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('result')->label('Result')->state(fn (InboundWebhookEvent $record): ?string => $record->result !== null ? (string) json_encode($record->result) : null)->placeholder('—'),
                TextColumn::make('last_error')->label('Error')->limit(40)->tooltip(fn (InboundWebhookEvent $record): ?string => $record->last_error)->placeholder('—'),
                TextColumn::make('received_at')->label('Received')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(InboundWebhookStatus::cases())->mapWithKeys(fn (InboundWebhookStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                Action::make('reprocess')
                    ->label('Process again')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn (InboundWebhookEvent $record): bool => in_array($record->status, [InboundWebhookStatus::Failed, InboundWebhookStatus::Ignored], true))
                    ->action(function (InboundWebhookEvent $record): void {
                        $actor = Filament::auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(IntegrationConnectionService::class)->reprocessInbound($record, $actor);
                        } catch (DomainException $e) {
                            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Event queued again')->success()->send();
                    }),
            ])
            ->recordUrl(null);
    }
}
