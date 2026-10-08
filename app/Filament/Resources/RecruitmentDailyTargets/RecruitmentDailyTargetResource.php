<?php

namespace App\Filament\Resources\RecruitmentDailyTargets;

use App\Filament\Resources\RecruitmentDailyTargets\Pages\CreateRecruitmentDailyTarget;
use App\Filament\Resources\RecruitmentDailyTargets\Pages\EditRecruitmentDailyTarget;
use App\Filament\Resources\RecruitmentDailyTargets\Pages\ListRecruitmentDailyTargets;
use App\Filament\Resources\RecruitmentDailyTargets\Pages\ViewRecruitmentDailyTarget;
use App\Filament\Resources\RecruitmentDailyTargets\Schemas\RecruitmentDailyTargetForm;
use App\Filament\Resources\RecruitmentDailyTargets\Tables\RecruitmentDailyTargetsTable;
use App\Models\RecruitmentDailyTarget;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RecruitmentDailyTargetResource extends Resource
{
    protected static ?string $model = RecruitmentDailyTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Recruiter Targets';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentDailyTargetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentDailyTargetsTable::configure($table);
    }

    /**
     * Phase 8.9 (P89-SEC-001): the list holds only the targets the user may see — RecruitmentDailyTarget::visibleTo.
     *
     * @return Builder<RecruitmentDailyTarget>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return parent::getEloquentQuery()->visibleTo($user);
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
            'index' => ListRecruitmentDailyTargets::route('/'),
            'create' => CreateRecruitmentDailyTarget::route('/create'),
            'view' => ViewRecruitmentDailyTarget::route('/{record}'),
            'edit' => EditRecruitmentDailyTarget::route('/{record}/edit'),
        ];
    }
}
