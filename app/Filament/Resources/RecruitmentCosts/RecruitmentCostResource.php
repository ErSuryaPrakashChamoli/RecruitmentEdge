<?php

namespace App\Filament\Resources\RecruitmentCosts;

use App\Filament\Resources\RecruitmentCosts\Pages\CreateRecruitmentCost;
use App\Filament\Resources\RecruitmentCosts\Pages\EditRecruitmentCost;
use App\Filament\Resources\RecruitmentCosts\Pages\ListRecruitmentCosts;
use App\Filament\Resources\RecruitmentCosts\Schemas\RecruitmentCostForm;
use App\Filament\Resources\RecruitmentCosts\Tables\RecruitmentCostsTable;
use App\Models\RecruitmentCost;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RecruitmentCostResource extends Resource
{
    protected static ?string $model = RecruitmentCost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyRupee;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Recruitment Costs';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentCostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentCostsTable::configure($table);
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
            'index' => ListRecruitmentCosts::route('/'),
            'create' => CreateRecruitmentCost::route('/create'),
            'edit' => EditRecruitmentCost::route('/{record}/edit'),
        ];
    }
}
