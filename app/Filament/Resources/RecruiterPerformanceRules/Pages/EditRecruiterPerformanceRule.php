<?php

namespace App\Filament\Resources\RecruiterPerformanceRules\Pages;

use App\Filament\Resources\RecruiterPerformanceRules\RecruiterPerformanceRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruiterPerformanceRule extends EditRecord
{
    protected static string $resource = RecruiterPerformanceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
