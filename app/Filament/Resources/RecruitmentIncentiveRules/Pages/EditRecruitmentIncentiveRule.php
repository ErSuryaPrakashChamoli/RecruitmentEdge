<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Pages;

use App\Filament\Resources\RecruitmentIncentiveRules\RecruitmentIncentiveRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentIncentiveRule extends EditRecord
{
    protected static string $resource = RecruitmentIncentiveRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
