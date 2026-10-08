<?php

namespace App\Filament\Resources\RecruitmentSettings\Pages;

use App\Filament\Resources\RecruitmentSettings\RecruitmentSettingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListRecruitmentSettings extends ListRecords
{
    protected static string $resource = RecruitmentSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('configure')
                ->label('Configure Settings')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->url(RecruitmentSettingResource::getUrl('configure')),
        ];
    }
}
