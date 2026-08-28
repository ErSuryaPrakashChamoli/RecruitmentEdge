<?php

namespace App\Filament\Resources\RecruitmentCosts\Pages;

use App\Filament\Resources\RecruitmentCosts\RecruitmentCostResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentCost extends EditRecord
{
    protected static string $resource = RecruitmentCostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
