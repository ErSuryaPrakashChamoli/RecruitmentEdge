<?php

namespace App\Filament\Resources\RecruiterPerformanceSnapshots;

use App\Filament\Resources\RecruiterPerformanceSnapshots\Pages\ListRecruiterPerformanceSnapshots;
use App\Filament\Resources\RecruiterPerformanceSnapshots\Schemas\RecruiterPerformanceSnapshotForm;
use App\Filament\Resources\RecruiterPerformanceSnapshots\Tables\RecruiterPerformanceSnapshotsTable;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only + a recalculate action: snapshots are a computed cache (PerformanceEngine), never
 * manually created or edited — see the missing create/edit pages.
 */
class RecruiterPerformanceSnapshotResource extends Resource
{
    protected static ?string $model = RecruiterPerformanceSnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Recruiter Performance';

    public static function form(Schema $schema): Schema
    {
        return RecruiterPerformanceSnapshotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruiterPerformanceSnapshotsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return $visibleIds === null ? $query : $query->whereIn('employee_id', $visibleIds);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruiterPerformanceSnapshots::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
