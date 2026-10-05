<?php

namespace App\Filament\Platform\Pages;

use App\Enums\PlatformCapability;
use App\Enums\SupportGrantStatus;
use App\Enums\SupportScope;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Services\Platform\PlatformDirectory;
use App\Services\Platform\SupportAccessService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * SaaS-5: support access across tenants — a support operator sees their own requests and grants
 * (and opens the workspace of an active one); a platform administrator sees all and can revoke.
 */
class SupportGrants extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $title = 'Support access';

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::SupportManage) || self::allows(PlatformCapability::TenantsManage);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(PlatformDirectory::class)->supportGrants(self::allows(PlatformCapability::TenantsManage) ? null : self::operator()->getKey()))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Tenant')->description(fn (SupportAccessGrant $record): ?string => $record->tenant?->slug),
                TextColumn::make('operator.user.name')->label('Operator'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SupportGrantStatus $state): string => $state->label())
                    ->color(fn (SupportGrantStatus $state): string => $state->color()),
                TextColumn::make('scopes')->label('Scopes')->badge()
                    ->state(fn (SupportAccessGrant $record): array => (array) ($record->scopes ?? $record->requested_scopes ?? [])),
                TextColumn::make('expires_at')->label('Ends')->dateTime()->placeholder('On approval'),
                TextColumn::make('use_count')->label('Uses')->numeric(),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('reason')->limit(40)->tooltip(fn (SupportAccessGrant $record): ?string => $record->reason),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(SupportGrantStatus::cases())->mapWithKeys(fn (SupportGrantStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open workspace')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (SupportAccessGrant $record): bool => $record->isActive() && $record->operator?->user_id === self::signedIn()?->getKey())
                    ->url(fn (SupportAccessGrant $record): string => SupportWorkspace::getUrl(['grant' => $record->getKey(), 'scope' => ((array) $record->scopes)[0] ?? SupportScope::Diagnostics->value])),
                Action::make('revoke')
                    ->label('End')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (SupportAccessGrant $record): bool => in_array($record->status, [SupportGrantStatus::Requested, SupportGrantStatus::Active], true))
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (SupportAccessGrant $record, array $data) => self::perform(fn (User $operator) => app(SupportAccessService::class)->revokeForPlatform($record, $operator, (string) $data['reason']), 'Support access ended')),
            ])
            ->recordUrl(null);
    }
}
