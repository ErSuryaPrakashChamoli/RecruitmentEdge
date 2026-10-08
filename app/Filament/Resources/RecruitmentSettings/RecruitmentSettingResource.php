<?php

namespace App\Filament\Resources\RecruitmentSettings;

use App\Filament\Resources\RecruitmentSettings\Pages\ListRecruitmentSettings;
use App\Filament\Resources\RecruitmentSettings\Pages\ManageRecruitmentConfiguration;
use App\Filament\Resources\RecruitmentSettings\Pages\ViewRecruitmentSetting;
use App\Filament\Resources\RecruitmentSettings\Schemas\RecruitmentSettingForm;
use App\Filament\Resources\RecruitmentSettings\Tables\RecruitmentSettingsTable;
use App\Models\RecruitmentSetting;
use App\Models\RecruitmentSettingChange;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 8.6 (D8.6-013): read-only list of the stored settings with each key's change history.
 * Settings are changed only on the typed Configure page (reason, validation, history) — there is
 * no raw create, edit or delete any more.
 */
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('key'),
            TextEntry::make('value'),
            TextEntry::make('type')->badge(),
            TextEntry::make('group')->badge()->color('gray'),
            TextEntry::make('description')->placeholder('—')->columnSpanFull(),
            RepeatableEntry::make('history')
                ->label('Change history')
                ->state(fn (RecruitmentSetting $record): array => RecruitmentSettingChange::query()
                    ->with('changedBy')
                    ->where('key', $record->key)
                    ->latest('effective_from')
                    ->latest('id')
                    ->get()
                    ->map(fn (RecruitmentSettingChange $change): array => [
                        'effective_from' => $change->effective_from,
                        'change' => ($change->old_value ?? '—').' → '.$change->new_value,
                        'by' => $change->changedBy?->name ?? 'System',
                        'reason' => $change->reason,
                    ])
                    ->all())
                ->schema([
                    TextEntry::make('effective_from')->label('Effective from')->dateTime(),
                    TextEntry::make('change'),
                    TextEntry::make('by'),
                    TextEntry::make('reason'),
                ])
                ->columns(4)
                ->placeholder('No recorded changes (changes before Phase 8.6 are in the audit log only).')
                ->columnSpanFull(),
        ]);
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
            'configure' => ManageRecruitmentConfiguration::route('/configure'),
            'view' => ViewRecruitmentSetting::route('/{record}'),
        ];
    }
}
