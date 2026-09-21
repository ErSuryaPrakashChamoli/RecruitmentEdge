<?php

namespace App\Filament\Resources\RecruiterPerformanceRules\Pages;

use App\Filament\Resources\RecruiterPerformanceRules\RecruiterPerformanceRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruiterPerformanceRule extends ViewRecord
{
    protected static string $resource = RecruiterPerformanceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
