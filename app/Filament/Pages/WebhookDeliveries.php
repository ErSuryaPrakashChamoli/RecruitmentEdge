<?php

namespace App\Filament\Pages;

use App\Enums\WebhookDeliveryStatus;
use App\Models\User;
use App\Models\WebhookDelivery;
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
 * SaaS-6: outbound webhook deliveries — status, attempts, the endpoint's answer — and a replay
 * (audited) for one that succeeded or failed.
 */
class WebhookDeliveries extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Webhook Deliveries';

    protected static ?string $title = 'Webhook Deliveries';

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
            ->query(fn () => WebhookDelivery::query()->with(['event:id,event_key,type,occurred_at', 'connection:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('event.type')->label('Event')->description(fn (WebhookDelivery $record): ?string => $record->event?->event_key),
                TextColumn::make('connection.name')->label('Endpoint'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (WebhookDeliveryStatus $state): string => $state->label())
                    ->color(fn (WebhookDeliveryStatus $state): string => $state->color()),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('response_status')->label('Answer')->placeholder('—'),
                TextColumn::make('last_error')->label('Error')->limit(40)->tooltip(fn (WebhookDelivery $record): ?string => $record->last_error)->placeholder('—'),
                TextColumn::make('next_attempt_at')->label('Next attempt')->since()->placeholder('—'),
                TextColumn::make('created_at')->label('Created')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(WebhookDeliveryStatus::cases())->mapWithKeys(fn (WebhookDeliveryStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                Action::make('replay')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Sends the same event (same event id) to the endpoint again.')
                    ->visible(fn (WebhookDelivery $record): bool => in_array($record->status, [WebhookDeliveryStatus::Succeeded, WebhookDeliveryStatus::Failed], true))
                    ->action(function (WebhookDelivery $record): void {
                        $actor = Filament::auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(IntegrationConnectionService::class)->replayDelivery($record, $actor);
                        } catch (DomainException $e) {
                            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Delivery queued again')->success()->send();
                    }),
            ])
            ->recordUrl(null);
    }
}
