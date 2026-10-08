<?php

namespace App\Filament\Resources\RecruitmentRejectionReasons\Pages;

use App\Filament\Actions\MasterDataLifecycleActions;
use App\Filament\Resources\RecruitmentRejectionReasons\RecruitmentRejectionReasonResource;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentRejectionReason extends EditRecord
{
    protected static string $resource = RecruitmentRejectionReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...MasterDataLifecycleActions::all(),
        ];
    }
}
