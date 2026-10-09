<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Filament\Platform\Pages\OperationalEvents;
use App\Models\PlatformEvent;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform commercial UI: the latest operational events on the overview; the full list (filters,
 * acknowledgement) stays on Operations → Events.
 */
class RecentPlatformEvents extends TableWidget
{
    use InteractsWithPlatform;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent platform events';

    public static function canView(): bool
    {
        return self::allows(PlatformCapability::OperationsView);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PlatformEvent::query()->with(['tenant:id,slug'])->latest('id')->limit(10))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->since(),
                TextColumn::make('severity')->badge()
                    ->formatStateUsing(fn (PlatformEventSeverity $state): string => $state->label())
                    ->color(fn (PlatformEventSeverity $state): string => $state->color()),
                TextColumn::make('tenant.slug')->label('Tenant')->placeholder('Platform'),
                TextColumn::make('title')->wrap(),
                TextColumn::make('acknowledged_at')->label('Acknowledged')->since()->placeholder('No'),
            ])
            ->headerActions([
                Action::make('all')->label('All events')->color('gray')->url(fn (): string => OperationalEvents::getUrl()),
            ])
            ->paginated(false)
            ->emptyStateHeading('No platform events');
    }
}
