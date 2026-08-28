<?php

namespace App\Filament\Resources\RecruitmentManualActivities\Pages;

use App\Filament\Resources\RecruitmentManualActivities\RecruitmentManualActivityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentManualActivities extends ListRecords
{
    protected static string $resource = RecruitmentManualActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
