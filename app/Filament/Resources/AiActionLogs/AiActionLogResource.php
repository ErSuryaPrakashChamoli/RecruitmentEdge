<?php

namespace App\Filament\Resources\AiActionLogs;

use App\Filament\Resources\AiActionLogs\Pages\ListAiActionLogs;
use App\Filament\Resources\AiActionLogs\Tables\AiActionLogsTable;
use App\Models\AiActionLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiActionLogResource extends Resource
{
    protected static ?string $model = AiActionLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'AI Action Audit';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AiActionLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiActionLogs::route('/'),
        ];
    }
}
