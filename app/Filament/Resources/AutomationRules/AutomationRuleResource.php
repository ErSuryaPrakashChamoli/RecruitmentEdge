<?php

namespace App\Filament\Resources\AutomationRules;

use App\Filament\Resources\AutomationRules\Pages\CreateAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\DryRunAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\EditAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\ListAutomationRules;
use App\Filament\Resources\AutomationRules\Pages\ViewAutomationRule;
use App\Filament\Resources\AutomationRules\RelationManagers\ExecutionsRelationManager;
use App\Filament\Resources\AutomationRules\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\AutomationRules\Schemas\AutomationRuleForm;
use App\Filament\Resources\AutomationRules\Schemas\AutomationRuleInfolist;
use App\Filament\Resources\AutomationRules\Tables\AutomationRulesTable;
use App\Models\AutomationRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 6 automation rules: the visual WHEN / IF / THEN / ESCALATE builder, versions, dry run and
 * lifecycle actions. All writes go through AutomationRuleService.
 */
class AutomationRuleResource extends Resource
{
    protected static ?string $model = AutomationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog8Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Automation Rules';

    protected static ?string $modelLabel = 'automation rule';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return AutomationRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AutomationRuleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutomationRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ExecutionsRelationManager::class,
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationRules::route('/'),
            'create' => CreateAutomationRule::route('/create'),
            'view' => ViewAutomationRule::route('/{record}'),
            'edit' => EditAutomationRule::route('/{record}/edit'),
            'dry-run' => DryRunAutomationRule::route('/{record}/dry-run'),
        ];
    }
}
