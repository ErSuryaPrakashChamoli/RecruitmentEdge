<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Pages;

use App\Filament\Resources\RecruitmentDailyActivities\RecruitmentDailyActivityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentDailyActivities extends ListRecords
{
    protected static string $resource = RecruitmentDailyActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
