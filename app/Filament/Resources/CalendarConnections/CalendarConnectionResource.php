<?php

namespace App\Filament\Resources\CalendarConnections;

use App\Filament\Resources\CalendarConnections\Pages\ListCalendarConnections;
use App\Filament\Resources\CalendarConnections\Tables\CalendarConnectionsTable;
use App\Models\CalendarConnection;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Connected external calendars (Phase 5): each employee sees their own; integration managers
 * see all.
 */
class CalendarConnectionResource extends Resource
{
    protected static ?string $model = CalendarConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Candidate Experience';

    protected static ?string $navigationLabel = 'Calendar Connections';

    public static function table(Table $table): Table
    {
        return CalendarConnectionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return parent::getEloquentQuery()
            ->with('employee')
            ->when(! $user->can('integrations.manage'), fn (Builder $q) => $q->where('employee_id', $user->employee_id));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalendarConnections::route('/'),
        ];
    }
}
