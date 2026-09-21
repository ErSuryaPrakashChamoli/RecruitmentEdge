<?php

namespace App\Filament\Resources\RecruitmentRejectionReasons\Pages;

use App\Filament\Resources\RecruitmentRejectionReasons\RecruitmentRejectionReasonResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentRejectionReason extends ViewRecord
{
    protected static string $resource = RecruitmentRejectionReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
