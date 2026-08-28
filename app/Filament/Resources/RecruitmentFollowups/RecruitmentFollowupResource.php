<?php

namespace App\Filament\Resources\RecruitmentFollowups;

use App\Filament\Resources\RecruitmentFollowups\Pages\CreateRecruitmentFollowup;
use App\Filament\Resources\RecruitmentFollowups\Pages\EditRecruitmentFollowup;
use App\Filament\Resources\RecruitmentFollowups\Pages\ListRecruitmentFollowups;
use App\Filament\Resources\RecruitmentFollowups\Schemas\RecruitmentFollowupForm;
use App\Filament\Resources\RecruitmentFollowups\Tables\RecruitmentFollowupsTable;
use App\Models\RecruitmentFollowup;
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

class RecruitmentFollowupResource extends Resource
{
    protected static ?string $model = RecruitmentFollowup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Follow-ups';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentFollowupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentFollowupsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return $visibleIds === null ? $query : $query->whereIn('recruiter_id', $visibleIds);
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
            'index' => ListRecruitmentFollowups::route('/'),
            'create' => CreateRecruitmentFollowup::route('/create'),
            'edit' => EditRecruitmentFollowup::route('/{record}/edit'),
        ];
    }
}
