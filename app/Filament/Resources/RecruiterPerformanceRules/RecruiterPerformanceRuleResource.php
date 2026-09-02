<?php

namespace App\Filament\Resources\RecruiterPerformanceRules;

use App\Filament\Resources\RecruiterPerformanceRules\Pages\CreateRecruiterPerformanceRule;
use App\Filament\Resources\RecruiterPerformanceRules\Pages\EditRecruiterPerformanceRule;
use App\Filament\Resources\RecruiterPerformanceRules\Pages\ListRecruiterPerformanceRules;
use App\Filament\Resources\RecruiterPerformanceRules\Schemas\RecruiterPerformanceRuleForm;
use App\Filament\Resources\RecruiterPerformanceRules\Tables\RecruiterPerformanceRulesTable;
use App\Models\RecruiterPerformanceRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RecruiterPerformanceRuleResource extends Resource
{
    protected static ?string $model = RecruiterPerformanceRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Performance Rules';

    public static function form(Schema $schema): Schema
    {
        return RecruiterPerformanceRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruiterPerformanceRulesTable::configure($table);
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
            'index' => ListRecruiterPerformanceRules::route('/'),
            'create' => CreateRecruiterPerformanceRule::route('/create'),
            'edit' => EditRecruiterPerformanceRule::route('/{record}/edit'),
        ];
    }
}
