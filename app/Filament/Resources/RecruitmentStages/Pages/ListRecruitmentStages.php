<?php

namespace App\Filament\Resources\RecruitmentStages\Pages;

use App\Filament\Resources\RecruitmentStages\RecruitmentStageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentStages extends ListRecords
{
    protected static string $resource = RecruitmentStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
