<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Filament\Platform\Pages\PlatformAudit;
use App\Services\Platform\PlatformDirectory;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform commercial UI: the latest platform actions on the overview (read-only); the full audit
 * stays on Compliance → Platform audit.
 */
class RecentPlatformAudit extends TableWidget
{
    use InteractsWithPlatform;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent platform activity';

    public static function canView(): bool
    {
        return self::allows(PlatformCapability::AuditView);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(PlatformDirectory::class)->audit()->with('tenant:id,slug')->latest('id')->limit(10))
            ->columns([
                TextColumn::make('created_at')->label('When')->since(),
                TextColumn::make('tenant.slug')->label('Tenant')->placeholder('Platform'),
                TextColumn::make('action'),
                TextColumn::make('user.name')->label('By')->placeholder('—'),
                TextColumn::make('reason')->limit(60)->placeholder('—'),
            ])
            ->headerActions([
                Action::make('all')->label('Full audit')->color('gray')->url(fn (): string => PlatformAudit::getUrl()),
            ])
            ->paginated(false)
            ->emptyStateHeading('No platform activity');
    }
}
