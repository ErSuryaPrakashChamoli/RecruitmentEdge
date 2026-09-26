<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates;

use App\Filament\Resources\RecruitmentPipelineTemplates\Pages\CreateRecruitmentPipelineTemplate;
use App\Filament\Resources\RecruitmentPipelineTemplates\Pages\EditRecruitmentPipelineTemplate;
use App\Filament\Resources\RecruitmentPipelineTemplates\Pages\ListRecruitmentPipelineTemplates;
use App\Filament\Resources\RecruitmentPipelineTemplates\Pages\ViewRecruitmentPipelineTemplate;
use App\Filament\Resources\RecruitmentPipelineTemplates\Schemas\RecruitmentPipelineTemplateForm;
use App\Filament\Resources\RecruitmentPipelineTemplates\Schemas\RecruitmentPipelineTemplateInfolist;
use App\Filament\Resources\RecruitmentPipelineTemplates\Tables\RecruitmentPipelineTemplatesTable;
use App\Models\RecruitmentPipelineTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 4 reusable hiring pipelines. Stage lists are written only through
 * PipelineTemplateService so validation, versioning and audit always apply.
 */
class RecruitmentPipelineTemplateResource extends Resource
{
    protected static ?string $model = RecruitmentPipelineTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Pipeline Templates';

    protected static ?string $modelLabel = 'pipeline template';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentPipelineTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecruitmentPipelineTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentPipelineTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruitmentPipelineTemplates::route('/'),
            'create' => CreateRecruitmentPipelineTemplate::route('/create'),
            'view' => ViewRecruitmentPipelineTemplate::route('/{record}'),
            'edit' => EditRecruitmentPipelineTemplate::route('/{record}/edit'),
        ];
    }
}
