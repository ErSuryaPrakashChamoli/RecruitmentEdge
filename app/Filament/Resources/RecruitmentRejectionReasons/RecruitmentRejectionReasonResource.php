<?php

namespace App\Filament\Resources\RecruitmentRejectionReasons;

use App\Filament\Resources\RecruitmentRejectionReasons\Pages\CreateRecruitmentRejectionReason;
use App\Filament\Resources\RecruitmentRejectionReasons\Pages\EditRecruitmentRejectionReason;
use App\Filament\Resources\RecruitmentRejectionReasons\Pages\ListRecruitmentRejectionReasons;
use App\Filament\Resources\RecruitmentRejectionReasons\Schemas\RecruitmentRejectionReasonForm;
use App\Filament\Resources\RecruitmentRejectionReasons\Tables\RecruitmentRejectionReasonsTable;
use App\Models\RecruitmentRejectionReason;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class RecruitmentRejectionReasonResource extends Resource
{
    protected static ?string $model = RecruitmentRejectionReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Rejection Reasons';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentRejectionReasonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentRejectionReasonsTable::configure($table);
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
            'index' => ListRecruitmentRejectionReasons::route('/'),
            'create' => CreateRecruitmentRejectionReason::route('/create'),
            'edit' => EditRecruitmentRejectionReason::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
