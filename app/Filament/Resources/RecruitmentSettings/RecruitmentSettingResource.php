<?php

namespace App\Filament\Resources\RecruitmentSettings;

use App\Filament\Resources\RecruitmentSettings\Pages\CreateRecruitmentSetting;
use App\Filament\Resources\RecruitmentSettings\Pages\EditRecruitmentSetting;
use App\Filament\Resources\RecruitmentSettings\Pages\ListRecruitmentSettings;
use App\Filament\Resources\RecruitmentSettings\Schemas\RecruitmentSettingForm;
use App\Filament\Resources\RecruitmentSettings\Tables\RecruitmentSettingsTable;
use App\Models\RecruitmentSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RecruitmentSettingResource extends Resource
{
    protected static ?string $model = RecruitmentSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Recruitment Settings';

    protected static ?string $recordTitleAttribute = 'key';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentSettingsTable::configure($table);
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
            'index' => ListRecruitmentSettings::route('/'),
            'create' => CreateRecruitmentSetting::route('/create'),
            'edit' => EditRecruitmentSetting::route('/{record}/edit'),
        ];
    }
}
