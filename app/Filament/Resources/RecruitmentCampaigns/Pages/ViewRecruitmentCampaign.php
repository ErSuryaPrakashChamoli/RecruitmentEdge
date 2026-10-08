<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Pages;

use App\Filament\Resources\RecruitmentCampaigns\RecruitmentCampaignResource;
use App\Filament\Resources\RecruitmentCampaigns\Widgets\CampaignMetrics;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentCampaign extends ViewRecord
{
    protected static string $resource = RecruitmentCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [CampaignMetrics::class];
    }
}
