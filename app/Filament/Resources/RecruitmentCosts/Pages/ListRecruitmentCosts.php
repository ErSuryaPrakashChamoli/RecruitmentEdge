<?php

namespace App\Filament\Resources\RecruitmentCosts\Pages;

use App\Filament\Resources\RecruitmentCosts\RecruitmentCostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentCosts extends ListRecords
{
    protected static string $resource = RecruitmentCostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
