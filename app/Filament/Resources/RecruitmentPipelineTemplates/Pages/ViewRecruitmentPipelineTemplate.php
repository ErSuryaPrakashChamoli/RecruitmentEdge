<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates\Pages;

use App\Filament\Resources\RecruitmentPipelineTemplates\RecruitmentPipelineTemplateResource;
use App\Filament\Resources\RecruitmentPipelineTemplates\Tables\RecruitmentPipelineTemplatesTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentPipelineTemplate extends ViewRecord
{
    protected static string $resource = RecruitmentPipelineTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            RecruitmentPipelineTemplatesTable::cloneAction(),
            RecruitmentPipelineTemplatesTable::setDefaultAction(),
        ];
    }
}
