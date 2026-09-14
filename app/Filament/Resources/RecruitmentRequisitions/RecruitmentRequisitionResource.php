<?php

namespace App\Filament\Resources\RecruitmentRequisitions;

use App\Enums\RequisitionStatus;
use App\Filament\Resources\RecruitmentRequisitions\Pages\CreateRecruitmentRequisition;
use App\Filament\Resources\RecruitmentRequisitions\Pages\EditRecruitmentRequisition;
use App\Filament\Resources\RecruitmentRequisitions\Pages\ListRecruitmentRequisitions;
use App\Filament\Resources\RecruitmentRequisitions\RelationManagers\ApplicationsRelationManager;
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
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Requisitions a new application may be raised against: Open, and visible to the current user
     * via the same hierarchy scoping as the list. Used for the requisition select options and as
     * the server-side guard when an application is created.
     *
     * @param  Builder<RecruitmentRequisition>|null  $query
     * @return Builder<RecruitmentRequisition>
     */
    public static function applicationTargetQuery(?Builder $query = null): Builder
    {
        return ($query ?? RecruitmentRequisition::query())
            ->where('recruitment_requisitions.status', RequisitionStatus::Open->value)
            ->whereIn('recruitment_requisitions.id', static::getEloquentQuery()->select('recruitment_requisitions.id'));
    }

    public static function getRelations(): array
    {
        return [
            ApplicationsRelationManager::class,
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

    /**
     * Powers the panel's global search (Cmd/Ctrl+K) — inherits hierarchy scoping from
     * getEloquentQuery() via getGlobalSearchEloquentQuery().
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'designation.name', 'department.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return "{$record->code} — ".($record->designation?->name ?? 'Requisition');
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Department' => $record->department?->name ?? '—',
            'Status' => $record->status->label(),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['designation', 'department']);
    }
}
