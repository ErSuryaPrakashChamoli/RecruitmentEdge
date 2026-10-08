<?php

namespace App\Filament\Platform\Pages;

use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\PlatformEvent;
use BackedEnum;
use Filament\Actions\Action;
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
use UnitEnum;

/**
 * SaaS-5: platform events — provisioning, lifecycle, support access, deletion and purge, exports —
 * for operators to see and acknowledge. Critical ones are also mailed (PlatformEvents).
 */
class OperationalEvents extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Events';

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::OperationsView);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PlatformEvent::query()->with(['tenant:id,slug']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime(),
                TextColumn::make('severity')->badge()
                    ->formatStateUsing(fn (PlatformEventSeverity $state): string => $state->label())
                    ->color(fn (PlatformEventSeverity $state): string => $state->color()),
                TextColumn::make('type'),
                TextColumn::make('tenant.slug')->label('Tenant')->placeholder('Platform'),
                TextColumn::make('title')->wrap(),
                TextColumn::make('acknowledged_at')->label('Acknowledged')->since()->placeholder('No'),
            ])
            ->filters([
                SelectFilter::make('severity')->options(collect(PlatformEventSeverity::cases())->mapWithKeys(fn (PlatformEventSeverity $severity): array => [$severity->value => $severity->label()])->all()),
                TernaryFilter::make('acknowledged')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('acknowledged_at'),
                        false: fn (Builder $query) => $query->whereNull('acknowledged_at'),
                    ),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->icon('heroicon-o-check')
                    ->visible(fn (PlatformEvent $record): bool => $record->acknowledged_at === null)
                    ->action(function (PlatformEvent $record): void {
                        PlatformEvent::query()->whereKey($record->getKey())->whereNull('acknowledged_at')->update(['acknowledged_at' => now(), 'acknowledged_by' => self::operator()->getKey(), 'updated_at' => now()]);
                    }),
            ])
            ->recordUrl(null);
    }
}
