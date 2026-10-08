<?php

namespace App\Filament\Resources\EmployeeSeparations;

use App\Filament\Resources\EmployeeSeparations\Pages\CreateEmployeeSeparation;
use App\Filament\Resources\EmployeeSeparations\Pages\EditEmployeeSeparation;
use App\Filament\Resources\EmployeeSeparations\Pages\ListEmployeeSeparations;
use App\Filament\Resources\EmployeeSeparations\Pages\ViewEmployeeSeparation;
use App\Filament\Resources\EmployeeSeparations\Schemas\EmployeeSeparationForm;
use App\Filament\Resources\EmployeeSeparations\Tables\EmployeeSeparationsTable;
use App\Models\EmployeeSeparation;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 8.2: the minimal separation record — the only reliable source for attrition in the
 * Outcome Loop. Hierarchy-scoped server-side; audited through the Auditable trait.
 */
class EmployeeSeparationResource extends Resource
{
    protected static ?string $model = EmployeeSeparation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Separations';

    protected static ?string $modelLabel = 'separation';

    public static function form(Schema $schema): Schema
    {
        return EmployeeSeparationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeSeparationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return parent::getEloquentQuery()
            ->with('employee')
            ->when($visibleIds !== null, fn (Builder $query) => $query->whereIn('employee_id', $visibleIds));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeSeparations::route('/'),
            'create' => CreateEmployeeSeparation::route('/create'),
            'view' => ViewEmployeeSeparation::route('/{record}'),
            'edit' => EditEmployeeSeparation::route('/{record}/edit'),
        ];
    }
}
