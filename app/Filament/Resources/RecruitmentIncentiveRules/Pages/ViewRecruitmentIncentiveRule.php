<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Pages;

use App\Filament\Resources\RecruitmentIncentiveRules\RecruitmentIncentiveRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentIncentiveRule extends ViewRecord
{
    protected static string $resource = RecruitmentIncentiveRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
