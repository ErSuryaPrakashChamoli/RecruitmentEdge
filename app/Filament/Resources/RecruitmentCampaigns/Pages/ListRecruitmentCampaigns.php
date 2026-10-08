<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Pages;

use App\Filament\Resources\RecruitmentCampaigns\RecruitmentCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentCampaigns extends ListRecords
{
    protected static string $resource = RecruitmentCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
