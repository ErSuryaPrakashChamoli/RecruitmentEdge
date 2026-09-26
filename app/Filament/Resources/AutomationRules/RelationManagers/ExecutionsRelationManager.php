<?php

namespace App\Filament\Resources\AutomationRules\RelationManagers;

use App\Filament\Resources\AutomationExecutions\AutomationExecutionResource;
use App\Filament\Resources\AutomationExecutions\Tables\AutomationExecutionsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * This rule's runs, limited to what the viewer may see (same scope as the Executions list).
 */
class ExecutionsRelationManager extends RelationManager
{
    protected static string $relationship = 'executions';

    protected static ?string $title = 'Recent runs';

    public function table(Table $table): Table
    {
        return AutomationExecutionsTable::configure($table, showRule: false)
            ->modifyQueryUsing(fn (Builder $query) => AutomationExecutionResource::scope($query)->with('actionExecutions'));
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('automation.executions') ?? false;
    }
}
