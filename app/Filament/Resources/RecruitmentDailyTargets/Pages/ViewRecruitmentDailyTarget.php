<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Pages;

use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentDailyTarget extends ViewRecord
{
    protected static string $resource = RecruitmentDailyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
