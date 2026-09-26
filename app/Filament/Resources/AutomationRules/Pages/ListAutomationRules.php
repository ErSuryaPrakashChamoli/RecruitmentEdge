<?php

namespace App\Filament\Resources\AutomationRules\Pages;

use App\Filament\Pages\AutomationTemplates;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAutomationRules extends ListRecords
{
    protected static string $resource = AutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('templates')->label('Start from a template')->icon('heroicon-o-squares-plus')->color('gray')->url(AutomationTemplates::getUrl())
                ->visible(fn () => AutomationTemplates::canAccess()),
            CreateAction::make()->label('New rule'),
        ];
    }
}
