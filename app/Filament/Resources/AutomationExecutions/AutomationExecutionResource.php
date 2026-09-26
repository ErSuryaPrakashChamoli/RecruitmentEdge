<?php

namespace App\Filament\Resources\AutomationExecutions;

use App\Filament\Resources\AutomationExecutions\Pages\ListAutomationExecutions;
use App\Filament\Resources\AutomationExecutions\Pages\ViewAutomationExecution;
use App\Filament\Resources\AutomationExecutions\Schemas\AutomationExecutionInfolist;
use App\Filament\Resources\AutomationExecutions\Tables\AutomationExecutionsTable;
use App\Models\AutomationExecution;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 6 execution history — "why did this happen?": rule, version, trigger, record, condition
 * trace, per-action results, escalations, retries. Scoped to runs about the viewer's team; runs
 * with no recruiter (requisition-level) only for users who see everything.
 */
class AutomationExecutionResource extends Resource
{
    protected static ?string $model = AutomationExecution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Executions';

    protected static ?string $modelLabel = 'automation run';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        return AutomationExecutionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutomationExecutionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::scope(parent::getEloquentQuery());
    }

    /**
     * @param  Builder<AutomationExecution>  $query
     * @return Builder<AutomationExecution>
     */
    public static function scope(Builder $query): Builder
    {
        $user = auth()->user();
        $visible = $user instanceof User ? app(HierarchyService::class)->visibleEmployeeIdsFor($user) : collect();

        return $query->when($visible !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visible));
    }

    /**
     * "Executions" plus a direct "Failures" entry (the same list on its Failed tab).
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return [
            ...parent::getNavigationItems(),
            NavigationItem::make('Automation Failures')
                ->group(static::getNavigationGroup())
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->sort(5)
                ->url(static::getUrl('index', ['activeTab' => 'failed']))
                ->badge(fn () => ($count = self::scope(AutomationExecution::query())->where('status', 'failed')->count()) > 0 ? (string) $count : null, 'danger')
                ->visible(fn () => static::canViewAny()),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationExecutions::route('/'),
            'view' => ViewAutomationExecution::route('/{record}'),
        ];
    }
}
