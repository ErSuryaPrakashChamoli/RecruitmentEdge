<?php

namespace App\Filament\Resources\RecruiterActions;

use App\Enums\RecruiterActionStatus;
use App\Filament\Resources\RecruiterActions\Pages\ListRecruiterActions;
use App\Filament\Resources\RecruiterActions\Tables\RecruiterActionsTable;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\RecruiterActionService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 6 Action Center: the viewer's own open work plus their team's (HierarchyService scope via
 * RecruiterActionService::visibleTo()). Items are created by automation rules, escalations and
 * managers, and change state only through RecruiterActionService.
 */
class RecruiterActionResource extends Resource
{
    protected static ?string $model = RecruiterAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?string $navigationLabel = 'Action Center';

    protected static ?string $modelLabel = 'action';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return RecruiterActionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(RecruiterActionService::class)->visibleTo($user)
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->employee_id === null) {
            return null;
        }

        $open = RecruiterAction::query()->where('owner_id', $user->employee_id)->whereIn('status', RecruiterActionStatus::openStatuses())->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruiterActions::route('/'),
        ];
    }
}
