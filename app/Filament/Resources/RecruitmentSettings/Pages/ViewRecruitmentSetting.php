<?php

namespace App\Filament\Resources\RecruitmentSettings\Pages;

use App\Filament\Resources\RecruitmentSettings\RecruitmentSettingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentSetting extends ViewRecord
{
    protected static string $resource = RecruitmentSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
