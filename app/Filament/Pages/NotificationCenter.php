<?php

namespace App\Filament\Pages;

use App\Enums\ActionPriority;
use App\Models\AutomationRule;
use App\Models\DatabaseNotification;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Persistent Notification Center (Phase 6) over the same database notifications the bell shows —
 * no second notification store. Everyone sees only their own notifications, with priority,
 * category, source automation and a "take action" link; they can mark read/unread and dismiss
 * (critical notifications only once read).
 */
class NotificationCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Notification Center';

    public static function getNavigationBadge(): ?string
    {
        $count = Filament::auth()->user()?->unreadNotifications()->count() ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DatabaseNotification::query()
                ->where('notifiable_type', (new User)->getMorphClass())
                ->where('notifiable_id', Filament::auth()->id()))
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('priority')
                    ->badge()
                    ->state(fn (DatabaseNotification $record) => self::priority($record)->notificationLabel())
                    ->color(fn (DatabaseNotification $record) => self::priority($record)->notificationColor()),
                TextColumn::make('title')
                    ->state(fn (DatabaseNotification $record) => preg_replace('/^\[[^\]]+\]\s*/', '', (string) ($record->data['title'] ?? '')))
                    ->description(fn (DatabaseNotification $record) => $record->data['body'] ?? null)
                    ->weight(fn (DatabaseNotification $record) => $record->read_at === null ? 'bold' : null)
                    ->wrap(),
                TextColumn::make('category')->state(fn (DatabaseNotification $record) => self::category($record))->badge()->color('gray'),
                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (DatabaseNotification $record) => self::ruleNames()->get($record->data['viewData']['rule_id'] ?? 0, isset($record->data['viewData']['rule_id']) ? 'Automation rule' : 'System')),
                TextColumn::make('created_at')->label('When')->since()->sortable(),
                TextColumn::make('read_at')->label('Read')->since()->placeholder('Unread')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('read')
                    ->placeholder('All')
                    ->trueLabel('Read')
                    ->falseLabel('Unread')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('read_at'), false: fn (Builder $query) => $query->whereNull('read_at')),
                SelectFilter::make('priority')
                    ->options(collect(ActionPriority::cases())->mapWithKeys(fn (ActionPriority $p) => [$p->value => $p->notificationLabel()])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $priority) => $q->where('data->viewData->priority', $priority))),
                SelectFilter::make('source')
                    ->options(['automation' => 'Automation rules', 'system' => 'System alerts'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'automation' => $query->whereNotNull('data->viewData->rule_id'),
                        'system' => $query->whereNull('data->viewData->rule_id'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Take action')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (DatabaseNotification $record) => self::url($record) !== null)
                    ->action(function (DatabaseNotification $record) {
                        $record->markAsRead();

                        return redirect(self::url($record));
                    }),
                Action::make('toggleRead')
                    ->label(fn (DatabaseNotification $record) => $record->read_at === null ? 'Mark read' : 'Mark unread')
                    ->icon('heroicon-o-envelope-open')
                    ->color('gray')
                    ->action(fn (DatabaseNotification $record) => $record->read_at === null ? $record->markAsRead() : $record->markAsUnread()),
                Action::make('dismiss')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (DatabaseNotification $record) => self::canDismiss($record))
                    ->action(function (DatabaseNotification $record): void {
                        $record->delete();
                        Notification::make()->title('Notification dismissed')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('markRead')->label('Mark read')->icon('heroicon-o-envelope-open')->action(fn (Collection $records) => $records->each->markAsRead()),
            ])
            ->headerActions([
                Action::make('markAllRead')->label('Mark all read')->color('gray')->action(fn () => Filament::auth()->user()->unreadNotifications->markAsRead()),
            ])
            ->emptyStateHeading('No notifications')
            ->emptyStateIcon('heroicon-o-bell');
    }

    public static function priority(DatabaseNotification $notification): ActionPriority
    {
        return ActionPriority::tryFrom((string) ($notification->data['viewData']['priority'] ?? ''))
            ?? ActionPriority::fromColor((string) ($notification->data['color'] ?? 'warning'));
    }

    public static function category(DatabaseNotification $notification): string
    {
        return $notification->data['viewData']['category']
            ?? (preg_match('/^\[([^\]]+)\]/', (string) ($notification->data['title'] ?? ''), $m) === 1 ? $m[1] : 'General');
    }

    public static function url(DatabaseNotification $notification): ?string
    {
        return $notification->data['actions'][0]['url'] ?? null;
    }

    /**
     * Critical notifications must be read before they can be dismissed.
     */
    public static function canDismiss(DatabaseNotification $notification): bool
    {
        return self::priority($notification) !== ActionPriority::Critical || $notification->read_at !== null;
    }

    /**
     * @return Collection<int, string>
     */
    private static function ruleNames(): Collection
    {
        return once(fn () => AutomationRule::query()->pluck('name', 'id'));
    }
}
