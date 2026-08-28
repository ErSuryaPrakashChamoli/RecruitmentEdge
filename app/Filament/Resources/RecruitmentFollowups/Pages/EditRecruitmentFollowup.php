<?php

namespace App\Filament\Resources\RecruitmentFollowups\Pages;

use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentFollowup extends EditRecord
{
    protected static string $resource = RecruitmentFollowupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
