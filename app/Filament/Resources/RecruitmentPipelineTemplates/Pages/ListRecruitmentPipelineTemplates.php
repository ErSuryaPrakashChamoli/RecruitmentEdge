<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates\Pages;

use App\Filament\Resources\RecruitmentPipelineTemplates\RecruitmentPipelineTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentPipelineTemplates extends ListRecords
{
    protected static string $resource = RecruitmentPipelineTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
