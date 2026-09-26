<?php

namespace App\Filament\Resources\RecruitmentStages\Pages;

use App\Filament\Resources\RecruitmentStages\RecruitmentStageResource;
use App\Filament\Resources\RecruitmentStages\Tables\RecruitmentStagesTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentStage extends ViewRecord
{
    protected static string $resource = RecruitmentStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            RecruitmentStagesTable::toggleActiveAction(),
        ];
    }
}
