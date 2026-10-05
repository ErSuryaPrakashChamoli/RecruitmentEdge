<?php

namespace App\Filament\Pages;

use App\Enums\SupportGrantStatus;
use App\Enums\SupportScope;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Services\Platform\SupportAccessService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
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
 * SaaS-5: this organisation's decisions on platform support access — requests from the platform's
 * support team (approve within what was asked, or deny) and access in force (revoke at any time).
 * Needs users.access.manage; SupportAccessService decides each step under the grant's lock. Support
 * sees only what an approved grant covers, read-only, until it ends — never signing in as anyone.
 */
class SupportAccess extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Support Access';

    protected static ?string $title = 'Support Access';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('users.access.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => SupportAccessGrant::query()->with('operator.user:id,name'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('operator.user.name')->label('Support operator'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SupportGrantStatus $state): string => $state->label())
                    ->color(fn (SupportGrantStatus $state): string => $state->color()),
                TextColumn::make('scopes')->label('Covers')->badge()
                    ->state(fn (SupportAccessGrant $record): array => (array) ($record->scopes ?? $record->requested_scopes ?? [])),
                TextColumn::make('requested_minutes')->label('Asked for (minutes)')->placeholder('—'),
                TextColumn::make('expires_at')->label('Ends')->dateTime()->placeholder('—'),
                TextColumn::make('use_count')->label('Views')->numeric(),
                TextColumn::make('last_used_at')->label('Last viewed')->since()->placeholder('Never'),
                TextColumn::make('reason')->wrap(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->visible(fn (SupportAccessGrant $record): bool => $record->status === SupportGrantStatus::Requested)
                    ->fillForm(fn (SupportAccessGrant $record): array => ['scopes' => (array) $record->requested_scopes, 'minutes' => $record->requested_minutes])
                    ->schema(fn (SupportAccessGrant $record): array => [
                        CheckboxList::make('scopes')->label('Allow support to see')
                            ->options(collect(SupportScope::cases())->filter(fn (SupportScope $scope): bool => in_array($scope->value, (array) $record->requested_scopes, true))->mapWithKeys(fn (SupportScope $scope): array => [$scope->value => $scope->label()])->all())
                            ->required(),
                        TextInput::make('minutes')->label('For (minutes)')->numeric()->integer()->minValue(1)->maxValue((int) $record->requested_minutes)->required(),
                    ])
                    ->action(fn (SupportAccessGrant $record, array $data) => self::perform(fn (User $actor) => app(SupportAccessService::class)->approve($record, array_map(fn (string $scope): SupportScope => SupportScope::from($scope), (array) $data['scopes']), (int) $data['minutes'], $actor), 'Support access approved')),
                Action::make('deny')
                    ->color('danger')
                    ->visible(fn (SupportAccessGrant $record): bool => $record->status === SupportGrantStatus::Requested)
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (SupportAccessGrant $record, array $data) => self::perform(fn (User $actor) => app(SupportAccessService::class)->deny($record, (string) $data['reason'], $actor), 'Support access denied')),
                Action::make('revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (SupportAccessGrant $record): bool => $record->status === SupportGrantStatus::Active && $record->isActive())
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (SupportAccessGrant $record, array $data) => self::perform(fn (User $actor) => app(SupportAccessService::class)->revoke($record, $actor, (string) $data['reason']), 'Support access revoked')),
            ])
            ->recordUrl(null);
    }

    /**
     * @param  callable(User): mixed  $work
     */
    private static function perform(callable $work, string $done): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $work($actor);
        } catch (DomainException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($done)->success()->send();
    }
}
