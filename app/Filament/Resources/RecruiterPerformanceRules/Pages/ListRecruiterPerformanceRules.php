<?php

namespace App\Filament\Resources\RecruiterPerformanceRules\Pages;

use App\Filament\Resources\RecruiterPerformanceRules\RecruiterPerformanceRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruiterPerformanceRules extends ListRecords
{
    protected static string $resource = RecruiterPerformanceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
