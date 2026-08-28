<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Pages;

use App\Filament\Resources\RecruitmentIncentiveRules\RecruitmentIncentiveRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentIncentiveRules extends ListRecords
{
    protected static string $resource = RecruitmentIncentiveRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
