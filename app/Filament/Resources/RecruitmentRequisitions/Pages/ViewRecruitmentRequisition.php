<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentRequisition extends ViewRecord
{
    protected static string $resource = RecruitmentRequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
