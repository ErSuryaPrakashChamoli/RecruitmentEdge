<?php

namespace App\Filament\Resources\RecruitmentManualActivities\Pages;

use App\Filament\Resources\RecruitmentManualActivities\RecruitmentManualActivityResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentManualActivity extends ViewRecord
{
    protected static string $resource = RecruitmentManualActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
