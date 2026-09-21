<?php

namespace App\Filament\Resources\RecruitmentFollowups\Pages;

use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentFollowup extends ViewRecord
{
    protected static string $resource = RecruitmentFollowupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
