<?php

namespace App\Filament\Resources\AiUsageLogs;

use App\Filament\Resources\AiUsageLogs\Pages\ListAiUsageLogs;
use App\Filament\Resources\AiUsageLogs\Tables\AiUsageLogsTable;
use App\Models\AiUsageLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiUsageLogResource extends Resource
{
    protected static ?string $model = AiUsageLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'AI Usage';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AiUsageLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiUsageLogs::route('/'),
        ];
    }
}
