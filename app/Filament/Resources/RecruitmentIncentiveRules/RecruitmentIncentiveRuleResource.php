<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules;

use App\Filament\Resources\RecruitmentIncentiveRules\Pages\CreateRecruitmentIncentiveRule;
use App\Filament\Resources\RecruitmentIncentiveRules\Pages\EditRecruitmentIncentiveRule;
use App\Filament\Resources\RecruitmentIncentiveRules\Pages\ListRecruitmentIncentiveRules;
use App\Filament\Resources\RecruitmentIncentiveRules\RelationManagers\SlabsRelationManager;
use App\Filament\Resources\RecruitmentIncentiveRules\Schemas\RecruitmentIncentiveRuleForm;
use App\Filament\Resources\RecruitmentIncentiveRules\Tables\RecruitmentIncentiveRulesTable;
use App\Models\RecruitmentIncentiveRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RecruitmentIncentiveRuleResource extends Resource
{
    protected static ?string $model = RecruitmentIncentiveRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Incentives';

    protected static ?string $navigationLabel = 'Incentive Rules';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentIncentiveRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentIncentiveRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SlabsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruitmentIncentiveRules::route('/'),
            'create' => CreateRecruitmentIncentiveRule::route('/create'),
            'edit' => EditRecruitmentIncentiveRule::route('/{record}/edit'),
        ];
    }
}
