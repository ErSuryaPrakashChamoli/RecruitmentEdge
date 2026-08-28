<?php

namespace App\Filament\Resources\RecruitmentFollowups\Pages;

use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentFollowups extends ListRecords
{
    protected static string $resource = RecruitmentFollowupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
