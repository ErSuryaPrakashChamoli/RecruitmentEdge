<?php

namespace App\Filament\Resources\RecruitmentRejectionReasons\Pages;

use App\Filament\Resources\RecruitmentRejectionReasons\RecruitmentRejectionReasonResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentRejectionReason extends EditRecord
{
    protected static string $resource = RecruitmentRejectionReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
