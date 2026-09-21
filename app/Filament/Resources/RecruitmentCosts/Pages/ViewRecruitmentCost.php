<?php

namespace App\Filament\Resources\RecruitmentCosts\Pages;

use App\Filament\Resources\RecruitmentCosts\RecruitmentCostResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentCost extends ViewRecord
{
    protected static string $resource = RecruitmentCostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
