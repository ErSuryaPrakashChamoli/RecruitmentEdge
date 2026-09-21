<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Pages;

use App\Filament\Resources\RecruitmentDailyActivities\RecruitmentDailyActivityResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentDailyActivity extends ViewRecord
{
    protected static string $resource = RecruitmentDailyActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
