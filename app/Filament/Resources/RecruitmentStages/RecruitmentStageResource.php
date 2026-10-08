<?php

namespace App\Filament\Resources\RecruitmentStages;

use App\Filament\Resources\RecruitmentStages\Pages\CreateRecruitmentStage;
use App\Filament\Resources\RecruitmentStages\Pages\EditRecruitmentStage;
use App\Filament\Resources\RecruitmentStages\Pages\ListRecruitmentStages;
use App\Filament\Resources\RecruitmentStages\Pages\ViewRecruitmentStage;
use App\Filament\Resources\RecruitmentStages\Schemas\RecruitmentStageForm;
use App\Filament\Resources\RecruitmentStages\Schemas\RecruitmentStageInfolist;
use App\Filament\Resources\RecruitmentStages\Tables\RecruitmentStagesTable;
use App\Models\RecruitmentStage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 4 Stage Builder: the organisation's library of configurable hiring stages. All writes go
 * through StageConfigurationService (see the Create/Edit pages and table actions).
 */
class RecruitmentStageResource extends Resource
{
    protected static ?string $model = RecruitmentStage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Hiring Stages';

    protected static ?string $modelLabel = 'hiring stage';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentStageForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecruitmentStageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentStagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruitmentStages::route('/'),
            'create' => CreateRecruitmentStage::route('/create'),
            'view' => ViewRecruitmentStage::route('/{record}'),
            'edit' => EditRecruitmentStage::route('/{record}/edit'),
        ];
    }
}
