<?php

namespace App\Filament\Resources\RecruitmentRequisitions;

use App\Filament\Resources\RecruitmentRequisitions\Pages\CreateRecruitmentRequisition;
use App\Filament\Resources\RecruitmentRequisitions\Pages\EditRecruitmentRequisition;
use App\Filament\Resources\RecruitmentRequisitions\Pages\ListRecruitmentRequisitions;
use App\Filament\Resources\RecruitmentRequisitions\RelationManagers\StatusHistoryRelationManager;
use App\Filament\Resources\RecruitmentRequisitions\Schemas\RecruitmentRequisitionForm;
use App\Filament\Resources\RecruitmentRequisitions\Tables\RecruitmentRequisitionsTable;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class RecruitmentRequisitionResource extends Resource
{
    protected static ?string $model = RecruitmentRequisition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentRequisitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentRequisitionsTable::configure($table);
    }

    /**
     * Scopes the list to requisitions the viewer has a stake in via their reporting hierarchy —
     * see RecruitmentRequisition::involvedEmployeeIds() / RecruitmentRequisitionPolicy for the
     * matching single-record check.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($visibleIds): void {
            $q->whereIn('reporting_manager_id', $visibleIds)
                ->orWhereIn('hiring_manager_id', $visibleIds)
                ->orWhereIn('assistant_manager_id', $visibleIds)
                ->orWhereIn('manager_id', $visibleIds)
                ->orWhereIn('vp_hr_id', $visibleIds)
                ->orWhereIn('created_by', $visibleIds)
                ->orWhereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $visibleIds));
        });
    }

    public static function getRelations(): array
    {
        return [
            StatusHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruitmentRequisitions::route('/'),
            'create' => CreateRecruitmentRequisition::route('/create'),
            'edit' => EditRecruitmentRequisition::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
